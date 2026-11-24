"""The pooled sclera is built from the eyes that passed every one of their checks.

``samples.sclera`` is the contract. Measuring it over the union of the two eye
masks - which is what pooling before judging amounts to - puts pixels from an eye
the code has just called unusable into the number a consumer reads, and re-admits
everything each eye's own filtering threw out.

One eye is broken here in five different ways, one per reason in the per-eye
vocabulary, and the question is the same every time: does the pooled sample equal
the good eye's?
"""

from __future__ import annotations

from collections.abc import Callable

import cv2
import numpy as np
import pytest

from color_engine.arrays import ImageArray
from color_engine.config import EngineConfig
from color_engine.detect import Detection
from color_engine.models import LabSample
from color_engine.sampling import Measurements, ScleraReason, sample_zones
from color_engine.zones import EYE_TOO_SMALL, LEFT_IRIS, RIGHT_EYE_OPENING, RIGHT_IRIS, build_zones

from .test_zones import _synthetic_detection

# The subject's LEFT eye is the good one throughout; the right is what gets
# broken. The two are deliberately different colours, so a pooled sample that
# included the wrong one would say so.
GOOD_WHITE = (232, 230, 226)
BAD_WHITE = (150, 196, 232)  # nothing like it, and just as bright
SKIN = (198, 154, 126)
IRIS = (74, 104, 132)


def _face(detection: Detection) -> ImageArray:
    """Paint the synthetic face: skin everywhere, two eyes, two irises."""
    size = detection.category_mask.shape[0]
    image = np.full((size, size, 3), SKIN, dtype=np.uint8)
    for centre, white in (((300, 335), BAD_WHITE), ((500, 335), GOOD_WHITE)):
        cv2.ellipse(image, centre, (46, 24), 0, 0, 360, white, thickness=-1)
        cv2.circle(image, centre, 18, IRIS, thickness=-1)
    return image


def _eye_too_small(detection: Detection, image: ImageArray) -> None:
    """Shrink the right iris so ``zones`` never builds the eye at all."""
    centre = detection.landmarks[RIGHT_IRIS[0]].copy()
    for offset, index in enumerate(RIGHT_IRIS[1:]):
        detection.landmarks[index] = centre + ((3.0, 0.0) if offset % 2 else (0.0, 3.0))


def _no_geometry(detection: Detection, image: ImageArray) -> None:
    """Collapse the right eye opening: the zone falls under the pixel floor."""
    centre = detection.landmarks[RIGHT_IRIS[0]].copy()
    for index in RIGHT_EYE_OPENING:
        detection.landmarks[index] = centre


def _nothing_survives_filtering(detection: Detection, image: ImageArray) -> None:
    """A narrow right eye - the zone clears the geometric floor - lit by a
    gradient, so the selection inside it leaves too little to measure.

    The other half of ``too_few_pixels``: the zone was built and then emptied by
    its own filtering, which the zone builder cannot see.
    """
    centre = detection.landmarks[RIGHT_IRIS[0]].copy()
    angles = np.linspace(0.0, 2.0 * np.pi, len(RIGHT_EYE_OPENING), endpoint=False)
    for index, angle in zip(RIGHT_EYE_OPENING, angles, strict=True):
        detection.landmarks[index] = (
            centre[0] + 28.0 * float(np.cos(angle)),
            centre[1] + 8.0 * float(np.sin(angle)),
        )
    for row in range(325, 346):
        shade = 150 + (row - 325) * 5
        image[row, 265:336] = (shade, shade, shade)
    cv2.circle(image, (300, 335), 18, IRIS, thickness=-1)


def _too_dark(detection: Detection, image: ImageArray) -> None:
    """A right eye far below the sanity floor - a mask on a lash line."""
    cv2.ellipse(image, (300, 335), (46, 24), 0, 0, 360, (28, 26, 24), thickness=-1)


def _too_noisy(detection: Detection, image: ImageArray) -> None:
    """A right eye that is two surfaces, not one."""
    for offset, shade in enumerate(((252, 233, 205), (214, 236, 255))):
        cv2.ellipse(image, (300, 335 - 8 + 16 * offset), (46, 8), 0, 0, 360, shade, thickness=-1)


def _more_chromatic_than_skin(detection: Detection, image: ImageArray) -> None:
    """A right eye painted the colour of the face - the sample landed on skin.

    The case both live frames hit. It is not dark and it is not dim; it is skin,
    and the only thing that says so is that it carries the skin's chroma.
    """
    cv2.ellipse(image, (300, 335), (46, 24), 0, 0, 360, (196, 152, 124), thickness=-1)
    cv2.circle(image, (300, 335), 18, IRIS, thickness=-1)


def _darker_than_skin(detection: Detection, image: ImageArray) -> None:
    """A right eye in neutral shadow: well under the face, but not coloured.

    Kept above the iris on purpose, so this isolates the against-the-skin test
    from the against-its-own-iris one - which is why this eye gets an iris of
    its own, painted darker than the shared one. The skin tolerance is no
    longer a flat 6 L*: on this face it is ~15, so isolating it needs a deeper
    shadow than before, and a deeper shadow needs a darker iris beneath it if
    the sample is still to clear its own eye. L* 40 against skin at L* 67 and
    an iris at L* 20.
    """
    cv2.ellipse(image, (300, 335), (46, 24), 0, 0, 360, (94, 94, 94), thickness=-1)
    cv2.circle(image, (300, 335), 18, (48, 48, 48), thickness=-1)


def _not_brighter_than_iris(detection: Detection, image: ImageArray) -> None:
    """A right eye no lighter than its own iris - so it is not the white of it."""
    cv2.ellipse(image, (300, 335), (46, 24), 0, 0, 360, (86, 112, 138), thickness=-1)
    cv2.circle(image, (300, 335), 18, IRIS, thickness=-1)


BREAKAGES: list[tuple[Callable[[Detection, ImageArray], None], ScleraReason]] = [
    (_eye_too_small, ScleraReason.EYE_TOO_SMALL),
    (_no_geometry, ScleraReason.TOO_FEW_PIXELS),
    (_nothing_survives_filtering, ScleraReason.TOO_FEW_PIXELS),
    (_too_dark, ScleraReason.TOO_DARK),
    (_too_noisy, ScleraReason.TOO_NOISY),
    (_more_chromatic_than_skin, ScleraReason.MORE_CHROMATIC_THAN_SKIN),
    (_darker_than_skin, ScleraReason.DARKER_THAN_SKIN),
    (_not_brighter_than_iris, ScleraReason.NOT_BRIGHTER_THAN_IRIS),
]


def _lab(sample: LabSample) -> tuple[float, float, float]:
    return (sample.L, sample.a, sample.b)


def _measure(
    break_it: Callable[[Detection, ImageArray], None], config: EngineConfig
) -> Measurements:
    detection = _synthetic_detection()
    image = _face(detection)
    break_it(detection, image)
    return sample_zones(image, build_zones(detection, config), config)


@pytest.mark.parametrize(
    ("break_it", "expected"), BREAKAGES, ids=lambda value: getattr(value, "__name__", value)
)
def test_a_rejected_eye_is_not_measured_into_the_pooled_sclera(
    break_it: Callable[[Detection, ImageArray], None],
    expected: ScleraReason,
    config: EngineConfig,
) -> None:
    measured = _measure(break_it, config)

    by_side = {side.side: side for side in measured.sclera_sides}
    good, bad = by_side["left"], by_side["right"]
    assert bad.usable is False, "the test did not break the eye it meant to"
    assert bad.quality.reason is expected
    assert good.usable is True, "the other eye has to survive for this to mean anything"

    sclera = measured.samples.sclera
    assert sclera is not None
    assert good.sample is not None
    assert _lab(sclera) == _lab(good.sample), "the rejected eye leaked into the pool"
    assert sclera.pixels == good.sample.pixels


def test_both_eyes_are_pooled_when_both_pass(config: EngineConfig) -> None:
    """The guard has to leave the normal case alone: the pool is bigger than
    either eye and is not equal to one of them."""
    detection = _synthetic_detection()
    image = np.full((*detection.category_mask.shape, 3), SKIN, dtype=np.uint8)
    for centre in ((300, 335), (500, 335)):
        cv2.ellipse(image, centre, (46, 24), 0, 0, 360, GOOD_WHITE, thickness=-1)
        cv2.circle(image, centre, 18, IRIS, thickness=-1)

    measured = sample_zones(image, build_zones(detection, config), config)

    sclera = measured.samples.sclera
    sides = {side.side: side for side in measured.sclera_sides}
    assert all(side.usable for side in sides.values())
    assert sclera is not None and sides["left"].sample is not None
    assert sclera.pixels > sides["left"].sample.pixels


def test_with_no_eye_left_the_sclera_is_absent_with_a_reason(config: EngineConfig) -> None:
    detection = _synthetic_detection()
    image = _face(detection)
    _too_dark(detection, image)
    cv2.ellipse(image, (500, 335), (46, 24), 0, 0, 360, (28, 26, 24), thickness=-1)

    measured = sample_zones(image, build_zones(detection, config), config)

    assert measured.samples.sclera is None
    assert measured.sclera_quality is not None
    assert measured.sclera_quality.reason is ScleraReason.TOO_DARK
    assert measured.sclera_quality.usable is False


def test_both_eyes_too_small_says_so(config: EngineConfig) -> None:
    detection = _synthetic_detection()
    image = _face(detection)
    for iris in (RIGHT_IRIS, LEFT_IRIS):
        centre = detection.landmarks[iris[0]].copy()
        for offset, index in enumerate(iris[1:]):
            detection.landmarks[index] = centre + ((3.0, 0.0) if offset % 2 else (0.0, 3.0))

    measured = sample_zones(image, build_zones(detection, config), config)

    assert measured.samples.sclera is None
    assert measured.sclera_quality is not None
    assert measured.sclera_quality.reason is ScleraReason.EYE_TOO_SMALL


def test_the_two_vocabularies_agree_on_the_string() -> None:
    """``zones`` cannot import ``sampling``, so the constant is spelled twice."""
    assert ScleraReason.EYE_TOO_SMALL.value == EYE_TOO_SMALL
