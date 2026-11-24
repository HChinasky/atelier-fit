"""The iris: a green eye with a black pupil, a dark rim, a lid and a catchlight.

The first live frame read the iris at L* 17.7 and C* 1.0 on light eyes — a
near-neutral near-black, which is the colour of a pupil, not of an iris. The
median was being taken over the pupil, the dark limbal ring and the shadow of
the upper lid. This builds that eye out of primitives and checks what comes back
is the colour a person would name.
"""

from __future__ import annotations

import colour
import cv2
import numpy as np

from color_engine.arrays import FloatArray, ImageArray, MaskArray
from color_engine.config import EngineConfig
from color_engine.detect import Detection
from color_engine.sampling import sample_iris
from color_engine.zones import LEFT_IRIS, RIGHT_EYE_OPENING, RIGHT_IRIS, build_zones

from .test_zones import _synthetic_detection

SIZE = 400
EYE = (200, 200)
IRIS_RADIUS = 46

GREEN_IRIS = (96, 138, 92)
DARK_LIMBUS = (26, 34, 26)  # the ring at the edge of every iris
PUPIL = (12, 12, 12)
LID = (58, 40, 38)
CATCHLIGHT = (255, 255, 255)
SCLERA = (232, 230, 226)


def _eye(pupil_radius: int = 24) -> tuple[ImageArray, MaskArray]:
    """An eye drawn outward: limbus, iris, pupil, then a lid over the top."""
    image = np.full((SIZE, SIZE, 3), SCLERA, dtype=np.uint8)
    cv2.circle(image, EYE, IRIS_RADIUS, DARK_LIMBUS, thickness=-1)
    cv2.circle(image, EYE, round(IRIS_RADIUS * 0.88), GREEN_IRIS, thickness=-1)
    cv2.circle(image, EYE, pupil_radius, PUPIL, thickness=-1)
    cv2.circle(image, (EYE[0] - 14, EYE[1] - 14), 6, CATCHLIGHT, thickness=-1)

    # The upper lid, crossing the top third of the iris, as it does on most
    # open eyes.
    cv2.rectangle(image, (0, 0), (SIZE, EYE[1] - 22), LID, thickness=-1)

    # The mask a perfect annulus builder would hand over: iris_inner_ratio to
    # iris_outer_ratio, clipped to what the lid leaves visible.
    mask = np.zeros((SIZE, SIZE), dtype=np.uint8)
    cv2.circle(mask, EYE, round(IRIS_RADIUS * 0.80), 255, thickness=-1)
    cv2.circle(mask, EYE, round(IRIS_RADIUS * 0.55), 0, thickness=-1)
    mask[: EYE[1] - 22] = 0

    return image, mask


def _lab(rgb: tuple[int, int, int]) -> FloatArray:
    values = np.asarray(rgb, dtype=np.float64) / 255.0

    return np.asarray(colour.XYZ_to_Lab(colour.sRGB_to_XYZ(values)), dtype=np.float64)


def _delta_e(sample: object, rgb: tuple[int, int, int]) -> float:
    measured = np.asarray([sample.L, sample.a, sample.b])  # type: ignore[attr-defined]

    return float(colour.delta_E(measured, _lab(rgb), method="CIE 2000"))


# --- the sample ------------------------------------------------------------


def test_the_iris_measures_the_colour_of_the_eye(config: EngineConfig) -> None:
    image, mask = _eye()

    sample = sample_iris(image, mask, config)

    assert sample is not None
    assert _delta_e(sample, GREEN_IRIS) < 4.0
    assert _delta_e(sample, PUPIL) > 30.0, "this is what the live frame was measuring"


def test_the_catchlight_does_not_drag_the_median(config: EngineConfig) -> None:
    """A glint is a picture of the lamp sitting in the middle of the thing being
    measured, and it survives a percentile trim on a dark eye."""
    lit, mask = _eye()
    unlit = lit.copy()
    cv2.circle(unlit, (EYE[0] - 14, EYE[1] - 14), 6, GREEN_IRIS, thickness=-1)

    with_glint = sample_iris(lit, mask, config)
    without = sample_iris(unlit, mask, config)

    assert with_glint is not None and without is not None
    assert abs(with_glint.L - without.L) < 1.5


def test_a_dilated_pupil_stays_out(config: EngineConfig) -> None:
    """Indoors a pupil is much wider than in daylight. A ratio tuned on a bright
    photo would put black into the sample of every dim one."""
    image, mask = _eye(pupil_radius=round(IRIS_RADIUS * 0.5))

    sample = sample_iris(image, mask, config)

    assert sample is not None
    assert _delta_e(sample, GREEN_IRIS) < 5.0


def test_a_brown_iris_is_not_cut_away_for_being_dark(config: EngineConfig) -> None:
    """A brown iris IS dark, all of it. A floor tuned so a blue eye reads well
    would leave a brown one with nothing to measure."""
    brown = (74, 48, 30)
    image, mask = _eye()
    image[mask > 0] = brown

    sample = sample_iris(image, mask, config)

    assert sample is not None
    assert _delta_e(sample, brown) < 2.0


def test_an_empty_mask_is_not_an_error(config: EngineConfig) -> None:
    image, _ = _eye()

    assert sample_iris(image, np.zeros((SIZE, SIZE), np.uint8), config) is None


# --- the mask ---------------------------------------------------------------


def _iris_mask(config: EngineConfig, detection: Detection | None = None) -> MaskArray:
    detection = detection if detection is not None else _synthetic_detection()

    zone = build_zones(detection, config).zones["iris_right"]

    return zone.mask


def test_the_annulus_excludes_the_pupil_and_the_limbal_ring(config: EngineConfig) -> None:
    detection = _synthetic_detection()
    centre = detection.landmarks[RIGHT_IRIS[0]]
    mask = _iris_mask(config, detection)

    ys, xs = np.nonzero(mask)
    distance = np.hypot(xs - centre[0], ys - centre[1])
    # The synthetic iris has radius 18.
    assert distance.min() >= 18 * config.iris_inner_ratio - 1.0
    assert distance.max() <= 18 * config.iris_outer_ratio + 1.0


def test_the_annulus_stops_where_the_lid_starts(config: EngineConfig) -> None:
    """On most open eyes the lid crosses the top of the iris, and the pixels
    under it are lid and lash, or the shadow they cast."""
    detection = _synthetic_detection()
    # Drop the eye opening to a narrow slit across the middle of the iris.
    centre = detection.landmarks[RIGHT_IRIS[0]].copy()
    angles = np.linspace(0.0, 2.0 * np.pi, len(RIGHT_EYE_OPENING), endpoint=False)
    for index, angle in zip(RIGHT_EYE_OPENING, angles, strict=True):
        detection.landmarks[index] = (
            centre[0] + 40.0 * float(np.cos(angle)),
            centre[1] + 6.0 * float(np.sin(angle)),
        )

    narrow = _iris_mask(config, detection)
    wide = _iris_mask(config)

    assert int(np.count_nonzero(narrow)) < int(np.count_nonzero(wide))
    ys, _ = np.nonzero(narrow)
    assert ys.size == 0 or abs(float(ys.mean()) - float(centre[1])) < 6.0


def test_both_eyes_get_the_same_treatment(config: EngineConfig) -> None:
    zones = build_zones(_synthetic_detection(), config)

    left = zones.zones["iris_left"].pixels
    right = zones.zones["iris_right"].pixels
    assert left > 0 and right > 0
    assert abs(left - right) / max(left, right) < 0.2
    assert LEFT_IRIS != RIGHT_IRIS
