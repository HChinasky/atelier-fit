"""Sampling: does a known colour under a known mask come back as the right LAB?"""

from __future__ import annotations

import colour
import numpy as np

from color_engine.arrays import FloatArray, ImageArray, MaskArray
from color_engine.config import EngineConfig
from color_engine.models import LabSample, SkinParts
from color_engine.sampling import aggregate_skin, rgb_to_lab, sample_zone

SKIN_LIKE = (198, 154, 126)


def _reference_lab(rgb: tuple[int, int, int]) -> FloatArray:
    values = np.asarray(rgb, dtype=np.float64) / 255.0
    return np.asarray(colour.XYZ_to_Lab(colour.sRGB_to_XYZ(values)), dtype=np.float64)


def _delta_e(sample_lab: FloatArray, reference: FloatArray) -> float:
    return float(colour.delta_E(sample_lab, reference, method="CIE 2000"))


def _canvas(color: tuple[int, int, int], size: int = 200) -> ImageArray:
    return np.full((size, size, 3), color, dtype=np.uint8)


def _mask(size: int = 200, box: tuple[int, int, int, int] = (50, 50, 150, 150)) -> MaskArray:
    mask = np.zeros((size, size), dtype=np.uint8)
    x0, y0, x1, y1 = box
    mask[y0:y1, x0:x1] = 255
    return mask


def test_flat_patch_matches_colour_science(config: EngineConfig) -> None:
    sample = sample_zone(_canvas(SKIN_LIKE), _mask(), config)

    assert sample is not None
    measured = np.asarray([sample.L, sample.a, sample.b])
    assert _delta_e(measured, _reference_lab(SKIN_LIKE)) < 0.5
    assert sample.L_iqr == 0.0
    assert sample.pixels == 100 * 100


def test_chroma_and_hue_are_derived_from_the_median(config: EngineConfig) -> None:
    sample = sample_zone(_canvas(SKIN_LIKE), _mask(), config)

    assert sample is not None
    assert round(float(np.hypot(sample.a, sample.b)), 3) == sample.C
    assert 0.0 <= sample.h < 360.0


def test_only_masked_pixels_are_measured(config: EngineConfig) -> None:
    image = _canvas((10, 10, 10))
    image[50:150, 50:150] = SKIN_LIKE

    sample = sample_zone(image, _mask(), config)

    assert sample is not None
    assert _delta_e(np.asarray([sample.L, sample.a, sample.b]), _reference_lab(SKIN_LIKE)) < 0.5


def test_specular_highlights_are_dropped(config: EngineConfig) -> None:
    """A blown-out patch covering under 10% of the zone must not move the median."""
    image = _canvas(SKIN_LIKE)
    mask = _mask()
    image[50:58, 50:150] = (255, 255, 255)  # 8 of 100 rows: inside the p90 trim

    sample = sample_zone(image, mask, config)

    assert sample is not None
    assert _delta_e(np.asarray([sample.L, sample.a, sample.b]), _reference_lab(SKIN_LIKE)) < 0.5
    assert sample.pixels < 100 * 100  # the trim really did remove pixels


def test_deep_shadow_is_dropped(config: EngineConfig) -> None:
    image = _canvas(SKIN_LIKE)
    image[142:150, 50:150] = (12, 8, 6)

    sample = sample_zone(image, _mask(), config)

    assert sample is not None
    assert _delta_e(np.asarray([sample.L, sample.a, sample.b]), _reference_lab(SKIN_LIKE)) < 0.5


def test_chroma_outlier_rejection_removes_a_lipstick_smear(config: EngineConfig) -> None:
    """Lipstick is bright *and* mid-lightness, so only the chroma filter catches it."""
    image = _canvas(SKIN_LIKE)
    image[60:85, 60:140] = (190, 40, 70)  # similar L*, wildly higher C*

    without = sample_zone(image, _mask(), config, reject_chroma_outliers=False)
    with_filter = sample_zone(image, _mask(), config, reject_chroma_outliers=True)

    assert without is not None and with_filter is not None
    reference = _reference_lab(SKIN_LIKE)
    assert _delta_e(np.asarray([with_filter.L, with_filter.a, with_filter.b]), reference) < 0.5
    assert with_filter.pixels < without.pixels


def test_zone_below_minimum_is_insufficient(config: EngineConfig) -> None:
    mask = np.zeros((200, 200), dtype=np.uint8)
    mask[0:5, 0:5] = 255  # 25 pixels, well under min_zone_pixels

    assert sample_zone(_canvas(SKIN_LIKE), mask, config) is None


def test_empty_mask_is_insufficient(config: EngineConfig) -> None:
    assert sample_zone(_canvas(SKIN_LIKE), np.zeros((200, 200), np.uint8), config) is None


def test_large_zone_is_subsampled_deterministically(config: EngineConfig) -> None:
    small = EngineConfig(max_pixels_per_zone=500)
    image = _canvas(SKIN_LIKE)

    first = sample_zone(image, _mask(), small)
    second = sample_zone(image, _mask(), small)

    assert first is not None and second is not None
    # 500 sampled, then the percentile trim takes its 20%.
    assert first.pixels <= 500
    assert first == second


def test_rgb_to_lab_handles_arrays_of_any_shape() -> None:
    block = np.full((4, 5, 3), SKIN_LIKE, dtype=np.uint8)

    lab = rgb_to_lab(block)

    assert lab.shape == (4, 5, 3)
    assert _delta_e(lab[0, 0], _reference_lab(SKIN_LIKE)) < 1e-6


def _part(lightness: float, a: float, b: float, pixels: int, iqr: float = 1.0) -> LabSample:
    return LabSample(
        L=lightness, a=a, b=b, C=float(np.hypot(a, b)), h=0.0, pixels=pixels, L_iqr=iqr
    )


def test_skin_aggregate_does_not_let_the_forehead_outvote_the_cheeks() -> None:
    """The forehead polygon is several times the area of a cheek. Area-weighted
    pooling would make it the answer; equal weighting must not."""
    parts = SkinParts(
        left_cheek=_part(69.0, 20.0, 19.0, pixels=740),
        right_cheek=_part(74.0, 20.0, 17.0, pixels=710),
        forehead=_part(85.0, 12.0, 12.0, pixels=5200),
    )

    skin = aggregate_skin(parts)

    assert skin is not None
    assert skin.L == 74.0  # the median of the three, not the 5200-pixel zone
    assert skin.a == 20.0
    assert skin.pixels == 740 + 710 + 5200


def test_skin_aggregate_uses_whatever_sub_zones_survived() -> None:
    parts = SkinParts(left_cheek=_part(70.0, 15.0, 15.0, pixels=500, iqr=4.0), forehead=None)

    skin = aggregate_skin(parts)

    assert skin is not None
    assert (skin.L, skin.pixels, skin.L_iqr) == (70.0, 500, 4.0)


def test_skin_aggregate_is_none_without_any_sub_zone() -> None:
    assert aggregate_skin(SkinParts()) is None


def test_skin_aggregate_reports_the_worst_sub_zone_spread() -> None:
    parts = SkinParts(
        left_cheek=_part(70.0, 15.0, 15.0, pixels=500, iqr=2.0),
        forehead=_part(80.0, 12.0, 12.0, pixels=500, iqr=9.0),
    )

    skin = aggregate_skin(parts)

    assert skin is not None
    assert skin.L_iqr == 9.0
