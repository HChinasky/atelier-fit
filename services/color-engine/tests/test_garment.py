"""Garment colour: does a known garment on a known backdrop come back as its colours?"""

from __future__ import annotations

import io

import colour
import numpy as np
import pytest
from PIL import Image

from color_engine import Engine
from color_engine.arrays import FloatArray, ImageArray, MaskArray
from color_engine.config import EngineConfig
from color_engine.detect import SegmentClass
from color_engine.garment import (
    GarmentMethod,
    GarmentRejectReason,
    GarmentStatus,
    analyze_garment,
    measure_garment,
)

WHITE = (250, 250, 250)
RED = (190, 20, 30)
NAVY = (30, 40, 90)
MUSTARD = (200, 160, 40)
SKIN = (198, 154, 126)


def _lab(rgb: tuple[int, int, int]) -> FloatArray:
    values = np.asarray(rgb, dtype=np.float64) / 255.0
    return np.asarray(colour.XYZ_to_Lab(colour.sRGB_to_XYZ(values)), dtype=np.float64)


def _delta_e(measured: object, rgb: tuple[int, int, int]) -> float:
    sample = np.asarray([measured.L, measured.a, measured.b])  # type: ignore[attr-defined]
    return float(colour.delta_E(sample, _lab(rgb), method="CIE 2000"))


def _flat(garment: tuple[int, int, int], size: int = 300) -> ImageArray:
    """A garment-shaped block on a studio backdrop, clear of the border."""
    image = np.full((size, size, 3), WHITE, dtype=np.uint8)
    image[60:260, 80:220] = garment
    return image


def _png(image: ImageArray) -> bytes:
    buffer = io.BytesIO()
    Image.fromarray(image).save(buffer, format="PNG")
    return buffer.getvalue()


# --- flat shots ---------------------------------------------------------------


def test_a_flat_shot_is_measured_off_its_backdrop(config: EngineConfig) -> None:
    result = measure_garment(_flat(RED), None, config)

    assert result.status is GarmentStatus.OK
    assert result.method is GarmentMethod.BACKGROUND
    assert len(result.colours) == 1
    assert _delta_e(result.colours[0], RED) < 1.0
    assert result.colours[0].share == 1.0
    assert result.coverage == pytest.approx(200 * 140 / 300**2, abs=0.01)


def test_two_colours_come_back_largest_first_with_their_shares(config: EngineConfig) -> None:
    image = _flat(NAVY)
    image[200:260, 80:220] = MUSTARD  # 60 of the 200 rows

    result = measure_garment(image, None, config)

    assert [round(colour.share, 2) for colour in result.colours] == [0.7, 0.3]
    assert _delta_e(result.colours[0], NAVY) < 1.0
    assert _delta_e(result.colours[1], MUSTARD) < 1.0


def test_one_dye_in_light_and_shade_is_one_colour(config: EngineConfig) -> None:
    """Folds darken a fabric a long way without moving its hue."""
    image = _flat(RED)
    image[60:130, 80:220] = (230, 40, 50)
    image[190:260, 80:220] = (120, 10, 18)

    result = measure_garment(image, None, config)

    assert len(result.colours) == 1
    assert result.colours[0].share == 1.0


def test_a_trim_below_the_minimum_share_is_not_reported(config: EngineConfig) -> None:
    image = _flat(NAVY)
    image[60:66, 80:220] = MUSTARD  # a 3% collar

    result = measure_garment(image, None, config)

    assert len(result.colours) == 1
    assert _delta_e(result.colours[0], NAVY) < 1.0


def test_a_garment_the_colour_of_its_backdrop_is_refused_not_measured(config: EngineConfig) -> None:
    result = measure_garment(_flat((246, 246, 244)), None, config)

    assert result.status is GarmentStatus.REJECTED
    assert result.reject_reason is GarmentRejectReason.NO_GARMENT
    assert result.colours == []


def test_the_colour_is_reported_as_a_screen_hex_and_its_lch(config: EngineConfig) -> None:
    measured = measure_garment(_flat(RED), None, config).colours[0]

    assert measured.hex == "#BE141E"
    assert measured.C == pytest.approx(float(np.hypot(measured.a, measured.b)), abs=0.01)
    assert 0.0 <= measured.h < 360.0


# --- worn shots ---------------------------------------------------------------


def _worn() -> tuple[ImageArray, MaskArray]:
    """A model in a navy top on a grey wall: face, arms, clothes."""
    image = np.full((300, 300, 3), (180, 180, 180), dtype=np.uint8)
    categories = np.full((300, 300), SegmentClass.BACKGROUND, dtype=np.uint8)

    image[20:80, 120:180] = SKIN
    categories[20:80, 120:180] = SegmentClass.FACE_SKIN
    image[90:250, 90:210] = NAVY
    categories[90:250, 90:210] = SegmentClass.CLOTHES
    image[90:250, 60:90] = SKIN
    categories[90:250, 60:90] = SegmentClass.BODY_SKIN
    return image, categories


def test_a_worn_shot_is_measured_on_the_clothes_class_not_the_skin(config: EngineConfig) -> None:
    image, categories = _worn()

    result = measure_garment(image, categories, config)

    assert result.method is GarmentMethod.SEGMENTER
    assert len(result.colours) == 1
    assert _delta_e(result.colours[0], NAVY) < 1.0


def test_no_person_in_frame_means_the_backdrop_decides(config: EngineConfig) -> None:
    """On a flat shot the segmenter, trained on people, is not trusted."""
    categories = np.full((300, 300), SegmentClass.BACKGROUND, dtype=np.uint8)
    categories[100:150, 100:150] = SegmentClass.CLOTHES

    result = measure_garment(_flat(RED), categories, config)

    assert result.method is GarmentMethod.BACKGROUND
    assert _delta_e(result.colours[0], RED) < 1.0


# --- decoding -----------------------------------------------------------------


def test_bytes_that_are_not_an_image_are_refused(config: EngineConfig) -> None:
    result = analyze_garment(b"not an image", config)

    assert result.status is GarmentStatus.REJECTED
    assert result.reject_reason is GarmentRejectReason.UNSUPPORTED_FORMAT


def test_a_narrow_shop_photo_is_measured_but_a_thumbnail_is_not(config: EngineConfig) -> None:
    narrow = np.full((606, 170, 3), WHITE, dtype=np.uint8)
    narrow[100:500, 40:130] = RED
    tiny = np.full((80, 80, 3), WHITE, dtype=np.uint8)

    assert analyze_garment(_png(narrow), config).status is GarmentStatus.OK
    assert (
        analyze_garment(_png(tiny), config).reject_reason is GarmentRejectReason.LOW_RESOLUTION
    )


def test_the_engine_measures_a_garment_with_its_own_segmenter(engine: Engine) -> None:
    result = engine.analyze_garment(_png(_flat(MUSTARD)))

    assert result.status is GarmentStatus.OK
    assert _delta_e(result.colours[0], MUSTARD) < 1.0
