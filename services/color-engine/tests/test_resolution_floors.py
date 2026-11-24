"""Absolute pixel floors: enough of the camera's own resolution to measure with.

``min_face_frame_ratio`` asks what share of the frame the face fills, which a
640x480 snapshot of a face filling the frame passes while carrying a fraction of
the detail of the same composition shot on a phone. These are the floors that
ask the other question - how many pixels the sensor actually put on the skin and
on the eye - and they are measured at source resolution, before our own
downscale and never after an upscale, because there is no upscale.
"""

from __future__ import annotations

import io as _io
from collections.abc import Callable

import cv2
import numpy as np
import pytest
from PIL import Image

from color_engine.arrays import ImageArray
from color_engine.config import EngineConfig
from color_engine.detect import Detection, too_small
from color_engine.io import load_image
from color_engine.sampling import sample_zone
from color_engine.zones import (
    EYE_TOO_SMALL,
    RIGHT_EYE_OPENING,
    RIGHT_IRIS,
    TOO_FEW_PIXELS,
    build_zones,
)

from .test_zones import _synthetic_detection

# A face filling two fifths of a small frame: any ratio test lets it through.
SMALL_FRAME = (360, 480)
BIG_FACE_IN_A_SMALL_FRAME = (0.0, 0.0, 140.0, 180.0)


def test_a_face_filling_a_small_frame_is_still_too_small(config: EngineConfig) -> None:
    """The case the ratio cannot see. 140px of face fills two fifths of a 360px
    frame - comfortably past min_face_frame_ratio - and still carries less
    detail than the floor asks for."""
    ratio = max(140 / 360, 180 / 480)

    assert ratio > config.min_face_frame_ratio, "the ratio test is not what rejects this"
    assert too_small(BIG_FACE_IN_A_SMALL_FRAME, SMALL_FRAME, 1.0, config) is True


def test_the_same_face_on_a_phone_passes(config: EngineConfig) -> None:
    frame = (3024, 4032)
    bbox = (0.0, 0.0, 1200.0, 1600.0)

    assert too_small(bbox, frame, 1.0, config) is False


def test_our_own_downscale_does_not_shrink_the_face(config: EngineConfig) -> None:
    """A 3024px-wide photo is analysed at 1600. The face is measured on the
    downscaled frame, so the floor has to multiply back through the scale, or we
    would be rejecting photos for something we did ourselves."""
    analysed_frame = (1200, 1600)
    bbox = (0.0, 0.0, 140.0, 185.0)  # 353px of face at source, 140 after

    assert too_small(bbox, analysed_frame, 3024 / 1200, config) is False
    assert too_small(bbox, analysed_frame, 1.0, config) is True


def test_a_face_lost_in_a_wide_shot_is_still_caught_by_the_ratio(
    config: EngineConfig,
) -> None:
    """Both questions are asked. A catalogue shot has plenty of face pixels and
    no face worth measuring."""
    assert too_small((0.0, 0.0, 300.0, 400.0), (4000, 6000), 1.0, config) is True


def test_the_face_floor_is_configurable(config: EngineConfig) -> None:
    lenient = config.model_copy(update={"min_face_width_px": 100})

    assert too_small(BIG_FACE_IN_A_SMALL_FRAME, SMALL_FRAME, 1.0, lenient) is False


# --- the iris floor ---------------------------------------------------------


def test_a_small_eye_drops_out_without_rejecting_the_photo(config: EngineConfig) -> None:
    """A photo taken a little too far away still measures skin perfectly well.
    The eye zones say why they are gone and the pipeline carries on."""
    # The synthetic irises are 36px across; ask for 60.
    strict = config.model_copy(update={"min_iris_diameter_px": 60.0})

    zones = build_zones(_synthetic_detection(), strict)

    for name in ("iris_left", "iris_right", "iris", "sclera_left", "sclera_right", "sclera"):
        assert zones.get(name) is None, name
        assert zones.reasons[name] == EYE_TOO_SMALL
    assert zones.get("skin") is not None, "skin is unaffected"
    assert zones.get("forehead") is not None


def test_a_normally_sized_eye_is_measured(config: EngineConfig) -> None:
    zones = build_zones(_synthetic_detection(), config)

    assert zones.get("iris") is not None
    assert "iris" not in zones.reasons


def test_one_usable_eye_keeps_the_pooled_zones(config: EngineConfig) -> None:
    """The floor is per eye. One iris badly resolved does not throw away the
    other, which is still a measurement."""
    detection = _synthetic_detection()
    centre = detection.landmarks[RIGHT_IRIS[0]].copy()
    for offset, index in enumerate(RIGHT_IRIS[1:]):
        detection.landmarks[index] = centre + ((3.0, 0.0) if offset % 2 else (0.0, 3.0))

    zones = build_zones(detection, config)

    assert zones.reasons["iris_right"] == EYE_TOO_SMALL
    assert zones.get("iris_left") is not None
    assert zones.get("iris") is not None, "one good eye is still an eye"
    assert "iris" not in zones.reasons


def test_a_zone_that_is_merely_empty_says_so_instead(config: EngineConfig) -> None:
    detection = _synthetic_detection()
    detection.category_mask[:] = 0  # no face-skin class at all

    zones = build_zones(detection, config)

    assert zones.reasons["forehead"] == TOO_FEW_PIXELS


# --- io never enlarges ------------------------------------------------------


def _png(size: tuple[int, int]) -> bytes:
    buffer = _io.BytesIO()
    Image.new("RGB", size, (140, 120, 100)).save(buffer, format="PNG")
    return buffer.getvalue()


@pytest.mark.parametrize("size", [(640, 480), (500, 490), (1600, 1200)])
def test_loading_never_enlarges_the_input(size: tuple[int, int], config: EngineConfig) -> None:
    """The absolute floors count pixels the camera captured, which only means
    anything if nothing on the way in invents any."""
    loaded = load_image(_png(size), config)

    assert (loaded.width, loaded.height) == size
    assert loaded.downscaled is False
    assert loaded.source_scale == 1.0


def test_a_downscaled_photo_reports_the_scale_back_to_source(config: EngineConfig) -> None:
    loaded = load_image(_png((3200, 2400)), config)

    assert max(loaded.width, loaded.height) == config.max_side
    assert loaded.downscaled is True
    assert loaded.source_scale == pytest.approx(2.0)


def test_the_scale_is_never_below_one(config: EngineConfig) -> None:
    loaded = load_image(_png((640, 480)), config)

    assert loaded.source_scale >= 1.0
    assert np.isfinite(loaded.source_scale)


# --- a rejected eye must not leak into the pooled zone ----------------------


def _two_toned_eyes() -> tuple[ImageArray, Detection]:
    """A synthetic face whose two eyes are deliberately different colours, so a
    pooled sample that included the wrong one would say so."""
    detection = _synthetic_detection()
    size = detection.category_mask.shape[0]
    image = np.full((size, size, 3), 40, dtype=np.uint8)
    cv2.circle(image, (300, 335), 60, GOOD_EYE, thickness=-1)  # subject's right
    cv2.circle(image, (500, 335), 60, BAD_EYE, thickness=-1)  # subject's left
    return image, detection


GOOD_EYE = (214, 212, 208)
BAD_EYE = (96, 150, 190)  # nothing like the other one, so a mix-up is visible


def _shrink_right_iris(detection: Detection) -> None:
    landmarks = detection.landmarks
    centre = landmarks[RIGHT_IRIS[0]].copy()
    for offset, index in enumerate(RIGHT_IRIS[1:]):
        landmarks[index] = centre + ((3.0, 0.0) if offset % 2 else (0.0, 3.0))


def _blank_right_eye(detection: Detection) -> None:
    """Collapse the right eye opening so its zone falls under the pixel floor."""
    landmarks = detection.landmarks
    centre = landmarks[RIGHT_IRIS[0]].copy()
    for index in RIGHT_EYE_OPENING:
        landmarks[index] = centre


@pytest.mark.parametrize(
    ("break_it", "zone", "expected"),
    [
        (_shrink_right_iris, "iris", EYE_TOO_SMALL),
        (_blank_right_eye, "sclera", TOO_FEW_PIXELS),
    ],
)
def test_a_rejected_eye_stays_out_of_the_pooled_zone(
    break_it: Callable[[Detection], None],
    zone: str,
    expected: str,
    config: EngineConfig,
) -> None:
    """An eye is rejected because we do not believe its pixels. Pooling them in
    anyway would put exactly those pixels into samples.iris and samples.sclera,
    which are the contract - and the leak is the same whatever the eye was
    rejected for, so both causes are checked."""
    _, detection = _two_toned_eyes()
    break_it(detection)  # the subject's left eye stays good

    zones = build_zones(detection, config)

    right = zones.zones[f"{zone}_right"]
    pooled = zones.get(zone)
    left = zones.get(f"{zone}_left")
    assert right.usable is False, "the test did not break the eye it meant to"
    assert right.reason == expected
    assert left is not None and pooled is not None
    assert not np.any(pooled.mask & right.mask), "the rejected eye leaked into the pool"
    assert np.array_equal(pooled.mask, left.mask), "one good eye is the whole pool"


def test_the_pooled_sample_is_the_good_eyes_colour(config: EngineConfig) -> None:
    image, detection = _two_toned_eyes()
    _shrink_right_iris(detection)

    zones = build_zones(detection, config)
    pooled = zones.get("iris")
    good = zones.get("iris_left")

    assert pooled is not None and good is not None
    pooled_sample = sample_zone(image, pooled.mask, config, min_pixels=1)
    good_sample = sample_zone(image, good.mask, config, min_pixels=1)
    assert pooled_sample is not None and good_sample is not None
    assert (pooled_sample.L, pooled_sample.a, pooled_sample.b) == (
        good_sample.L,
        good_sample.a,
        good_sample.b,
    )


def test_two_good_eyes_are_still_pooled_together(config: EngineConfig) -> None:
    """The guard has to leave the normal case alone."""
    _, detection = _two_toned_eyes()

    zones = build_zones(detection, config)
    pooled = zones.get("iris")
    left = zones.get("iris_left")

    assert pooled is not None and left is not None
    assert pooled.pixels > left.pixels
