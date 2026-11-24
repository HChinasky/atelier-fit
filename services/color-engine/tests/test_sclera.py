"""The sclera: a white patch with a coloured iris, a dark lid and a catchlight.

The sclera is the one zone whose signal is the bright, neutral end of its own
distribution, so it has its own selection rules. This builds an eye out of
primitives and checks that what comes back is the white of it.
"""

from __future__ import annotations

import colour
import cv2
import numpy as np

from color_engine.arrays import FloatArray, ImageArray, MaskArray
from color_engine.config import EngineConfig
from color_engine.sampling import ScleraReason, sample_sclera

SIZE = 400
EYE_CENTRE = (200, 200)
EYE_AXES = (140, 60)
IRIS_RADIUS = 46

WHITE_OF_EYE = (232, 230, 226)  # L* ~91: a real sclera is bright but not clipped
IRIS_COLOR = (74, 104, 132)  # blue-grey
LID_COLOR = (58, 40, 38)  # dark lash line / liner
CATCHLIGHT = (255, 255, 255)


def _eye() -> tuple[ImageArray, MaskArray]:
    """A synthetic eye and the mask a perfect zone builder would hand over.

    The mask deliberately includes the lid edge and the catchlight: those are
    what the selection has to throw out.
    """
    image = np.full((SIZE, SIZE, 3), 20, dtype=np.uint8)
    # Lid first, then the opening inside it, so the eye is ringed in dark.
    cv2.ellipse(image, EYE_CENTRE, EYE_AXES, 0, 0, 360, LID_COLOR, thickness=-1)
    cv2.ellipse(
        image,
        EYE_CENTRE,
        (EYE_AXES[0] - 14, EYE_AXES[1] - 14),
        0,
        0,
        360,
        WHITE_OF_EYE,
        thickness=-1,
    )
    cv2.circle(image, EYE_CENTRE, IRIS_RADIUS, IRIS_COLOR, thickness=-1)
    # A small blown-out catchlight on the sclera, left of the iris.
    cv2.circle(image, (EYE_CENTRE[0] - 80, EYE_CENTRE[1]), 7, CATCHLIGHT, thickness=-1)

    # The mask: the whole opening including the lid ring, minus the iris - i.e.
    # an honestly imperfect mask.
    mask = np.zeros((SIZE, SIZE), dtype=np.uint8)
    cv2.ellipse(mask, EYE_CENTRE, EYE_AXES, 0, 0, 360, 255, thickness=-1)
    cv2.circle(mask, EYE_CENTRE, IRIS_RADIUS, 0, thickness=-1)
    return image, mask


def _lab(rgb: tuple[int, int, int]) -> FloatArray:
    values = np.asarray(rgb, dtype=np.float64) / 255.0
    return np.asarray(colour.XYZ_to_Lab(colour.sRGB_to_XYZ(values)), dtype=np.float64)


def _delta_e(sample: object, rgb: tuple[int, int, int]) -> float:
    measured = np.asarray([sample.L, sample.a, sample.b])  # type: ignore[attr-defined]
    return float(colour.delta_E(measured, _lab(rgb), method="CIE 2000"))


def test_sclera_measures_the_white_of_the_eye(config: EngineConfig) -> None:
    image, mask = _eye()

    sample, quality = sample_sclera(image, mask, config)

    assert sample is not None
    assert _delta_e(sample, WHITE_OF_EYE) < 2.0
    assert quality.usable is True


def test_the_dark_lid_ring_is_not_in_the_sample(config: EngineConfig) -> None:
    image, mask = _eye()

    sample, _ = sample_sclera(image, mask, config)

    assert sample is not None
    # The lid is L* ~19; anything near it would show up as a much lower median.
    assert sample.L > 80.0
    assert _delta_e(sample, LID_COLOR) > 40.0


def test_the_catchlight_is_removed(config: EngineConfig) -> None:
    image, mask = _eye()

    _, quality = sample_sclera(image, mask, config)

    assert quality.specular_pixels > 0
    # A 7px-radius disc is ~150 pixels; nothing like the whole mask.
    assert quality.specular_pixels < 400


def test_a_large_bright_region_is_not_mistaken_for_a_catchlight(
    config: EngineConfig,
) -> None:
    """A correctly exposed sclera is itself bright and near-neutral. Only small
    connected blobs are glints."""
    image = np.full((SIZE, SIZE, 3), 250, dtype=np.uint8)
    mask = np.zeros((SIZE, SIZE), dtype=np.uint8)
    cv2.ellipse(mask, EYE_CENTRE, EYE_AXES, 0, 0, 360, 255, thickness=-1)

    sample, quality = sample_sclera(image, mask, config)

    assert sample is not None
    assert quality.specular_pixels == 0
    assert sample.L > 95.0


def test_coloured_pixels_are_dropped(config: EngineConfig) -> None:
    image, mask = _eye()
    # Smear "eyeliner" across the lower half of the opening.
    cv2.ellipse(image, (EYE_CENTRE[0], EYE_CENTRE[1] + 40), (110, 18), 0, 0, 360, (150, 40, 90), -1)

    sample, quality = sample_sclera(image, mask, config)

    assert sample is not None
    assert _delta_e(sample, WHITE_OF_EYE) < 3.0
    assert quality.dropped_fraction > 0.3


def test_quality_reports_how_much_survived(config: EngineConfig) -> None:
    image, mask = _eye()

    _, quality = sample_sclera(image, mask, config)

    assert quality.pixels_before == int(np.count_nonzero(mask))
    assert 0 < quality.pixels_after < quality.pixels_before
    assert 0.0 < quality.dropped_fraction < 1.0
    assert quality.as_dict()["usable"] is True


def test_a_closed_eye_is_insufficient_not_an_error(config: EngineConfig) -> None:
    """A squint or a blink is a normal photo. The zone is simply not measured."""
    image, _ = _eye()
    sliver = np.zeros((SIZE, SIZE), dtype=np.uint8)
    cv2.ellipse(sliver, EYE_CENTRE, (6, 1), 0, 0, 360, 255, thickness=-1)

    sample, quality = sample_sclera(image, sliver, config)

    assert sample is None
    assert quality.usable is False


def test_an_empty_mask_is_handled(config: EngineConfig) -> None:
    image, _ = _eye()

    sample, quality = sample_sclera(image, np.zeros((SIZE, SIZE), np.uint8), config)

    assert sample is None
    assert quality.pixels_before == 0
    assert quality.dropped_fraction == 0.0
    assert quality.usable is False


def test_a_dark_patch_is_rejected_by_the_sanity_floor(config: EngineConfig) -> None:
    """If the mask sat on the lid rather than the eye, say so rather than
    handing white balance a bad reference. This is the only absolute L* check
    left, and it is set far below any plausible sclera."""
    image = np.full((SIZE, SIZE, 3), 30, dtype=np.uint8)  # L* ~11
    mask = np.zeros((SIZE, SIZE), dtype=np.uint8)
    cv2.ellipse(mask, EYE_CENTRE, EYE_AXES, 0, 0, 360, 255, thickness=-1)

    sample, quality = sample_sclera(image, mask, config)

    assert sample is not None
    assert quality.reason is ScleraReason.TOO_DARK
    assert quality.usable is False


def test_an_underexposed_sclera_is_still_usable(config: EngineConfig) -> None:
    """L* tracks exposure, not the colour of the light. A dark frame with a
    clean neutral sclera is exactly the case the old absolute threshold threw
    away, and it is a case white balance can work with."""
    image, mask = _eye()
    dim = (image.astype(np.float64) * 0.45).astype(np.uint8)

    sample, quality = sample_sclera(dim, mask, config)

    assert sample is not None
    assert sample.L < 70.0, "this frame really is underexposed"
    assert quality.usable is True
    assert quality.reason is ScleraReason.OK


def test_a_warm_cast_does_not_destroy_the_sclera_zone(config: EngineConfig) -> None:
    """The measurement that matters most under a tungsten bulb is the sclera,
    and under one it is genuinely chromatic. An absolute chroma cap would drop
    the whole zone precisely when the cast is strongest."""
    image, mask = _eye()
    warm = np.clip(image.astype(np.float64) * (1.35, 0.98, 0.62), 0, 255).astype(np.uint8)

    sample, quality = sample_sclera(warm, mask, config)

    assert sample is not None
    assert sample.C > 20.0, "the synthetic cast is not strong enough to prove the point"
    assert quality.usable is True
    assert quality.chroma_base is not None and quality.chroma_base > 20.0


def test_the_lid_ring_is_still_dropped_under_a_warm_cast(config: EngineConfig) -> None:
    """Relative does not mean permissive: the lid is still far more chromatic
    than the white of the same eye."""
    image, mask = _eye()
    warm = np.clip(image.astype(np.float64) * (1.35, 0.98, 0.62), 0, 255).astype(np.uint8)

    sample, _ = sample_sclera(warm, mask, config)

    assert sample is not None
    assert sample.L > 80.0


def test_a_sample_straddling_two_colours_is_too_noisy(config: EngineConfig) -> None:
    """Half white of the eye, half something else: the median may look sane,
    the spread says the zone is not one surface."""
    image = np.full((SIZE, SIZE, 3), 20, dtype=np.uint8)
    mask = np.zeros((SIZE, SIZE), dtype=np.uint8)
    cv2.ellipse(mask, EYE_CENTRE, EYE_AXES, 0, 0, 360, 255, thickness=-1)
    image[mask > 0] = WHITE_OF_EYE
    # Two fifths of the zone, as bright as the sclera but 14 units away in a*b*.
    # Bright enough to survive the L* trim, which is the point: lightness alone
    # cannot see this contaminant, and a median would shrug it off.
    ys, xs = np.nonzero(mask)
    spoiled = slice(0, int(ys.size * 0.4))
    image[ys[spoiled], xs[spoiled]] = (252, 233, 205)

    _, quality = sample_sclera(image, mask, config)

    assert quality.ab_spread is not None and quality.ab_spread > config.sclera_max_ab_spread
    assert quality.reason is ScleraReason.TOO_NOISY
    assert quality.usable is False


def test_quality_reports_the_base_chroma_it_measured_against(config: EngineConfig) -> None:
    image, mask = _eye()

    _, quality = sample_sclera(image, mask, config)

    assert quality.chroma_base is not None
    assert 0.0 <= quality.chroma_base < 10.0
    assert quality.as_dict()["chroma_base"] == quality.chroma_base
    assert quality.as_dict()["reason"] == "ok"
