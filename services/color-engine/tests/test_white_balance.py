"""White balance: what the light was, and what taking it out does.

Everything here is synthetic and runs without models or photos. The cast is
applied with the same chromatic adaptation the estimator inverts, so these tests
prove the *algebra* - that the estimate recovers the illuminant it was given and
that the correction undoes it. They say nothing about whether the estimate
survives a real phone JPEG; that is what the fixtures-marked tests and the
stability report are for.
"""

from __future__ import annotations

import colour
import cv2
import numpy as np

from color_engine.arrays import FloatArray, ImageArray, MaskArray
from color_engine.config import EngineConfig
from color_engine.models import (
    EyeParts,
    LabSample,
    RejectReason,
    Samples,
    SkinParts,
    WhiteBalanceMethod,
    WhiteBalanceReason,
)
from color_engine.sampling import ScleraQuality, ScleraReason, ScleraSide
from color_engine.white_balance import (
    CAST_SUSPECTED,
    D65_XYZ,
    adapt_image,
    adapt_sample,
    adapt_samples,
    apply_white_balance,
    assess_sclera,
    estimate_from_scene,
    illuminant_from_sclera,
    white_balance_info,
)
from color_engine.zones import Zone, Zones

ILLUMINANTS = colour.CCS_ILLUMINANTS["CIE 1931 2 Degree Standard Observer"]

# A plausible face, in CIELAB under D65: what the camera *should* have recorded.
TRUE_SKIN = (70.0, 15.0, 18.0)
TRUE_SCLERA = (88.0, 0.0, 0.0)
TRUE_HAIR = (25.0, 4.0, 6.0)

# The illuminants a room throws at a phone, plus one that is not a room.
ROOM_LIGHTS = ("A", "FL2", "FL11", "D50", "D75")
COLOURED_WASH = (0.55, 0.40)  # a stage light: far enough off to be uncorrectable


def _white(name: str) -> FloatArray:
    xyz = np.asarray(colour.xy_to_XYZ(ILLUMINANTS[name]), dtype=np.float64)
    return np.asarray(xyz / xyz[1], dtype=np.float64)


def _white_xy(xy: tuple[float, float]) -> FloatArray:
    xyz = np.asarray(colour.xy_to_XYZ(np.asarray(xy, dtype=np.float64)), dtype=np.float64)
    return np.asarray(xyz / xyz[1], dtype=np.float64)


def _lit_by(lab: tuple[float, float, float], white: FloatArray) -> tuple[float, float, float]:
    """What a surface of this D65 colour measures as when lit by ``white``."""
    xyz = colour.Lab_to_XYZ(np.asarray(lab, dtype=np.float64))
    under = colour.adaptation.chromatic_adaptation_VonKries(xyz, D65_XYZ, white, transform="CAT16")
    values = colour.XYZ_to_Lab(under)
    return (float(values[0]), float(values[1]), float(values[2]))


def _sample(lab: tuple[float, float, float], pixels: int = 500) -> LabSample:
    lightness, a, b = lab
    return LabSample(
        L=round(lightness, 3),
        a=round(a, 3),
        b=round(b, 3),
        C=round(float(np.hypot(a, b)), 3),
        h=round(float(np.degrees(np.arctan2(b, a)) % 360.0), 3),
        pixels=pixels,
        L_iqr=2.0,
    )


def _delta_e(sample: LabSample, lab: tuple[float, float, float]) -> float:
    return float(
        colour.delta_E(
            np.asarray([sample.L, sample.a, sample.b], dtype=np.float64),
            np.asarray(lab, dtype=np.float64),
            method="CIE 2000",
        )
    )


def _side(side: str, lab: tuple[float, float, float] | None, **quality: object) -> ScleraSide:
    defaults: dict[str, object] = {
        "pixels_before": 400,
        "pixels_after": 200,
        "specular_pixels": 0,
        "median_L": None if lab is None else lab[0],
        "median_C": None,
        "chroma_base": 2.0,
        "ab_spread": 2.0,
        "reason": ScleraReason.OK,
    }
    defaults.update(quality)
    return ScleraSide(
        side=side,
        sample=None if lab is None else _sample(lab),
        quality=ScleraQuality(**defaults),  # type: ignore[arg-type]
    )


def _samples(
    skin: tuple[float, float, float] | None = TRUE_SKIN,
    sclera: tuple[float, float, float] | None = TRUE_SCLERA,
    hair: tuple[float, float, float] | None = TRUE_HAIR,
) -> Samples:
    return Samples(
        skin=None if skin is None else _sample(skin),
        skin_parts=SkinParts(left_cheek=None if skin is None else _sample(skin)),
        hair=None if hair is None else _sample(hair),
        sclera=None if sclera is None else _sample(sclera),
    )


def _scene_zones(image: ImageArray, mask: MaskArray | None = None) -> Zones:
    if mask is None:
        mask = np.full(image.shape[:2], 255, dtype=np.uint8)
    pixels = int(np.count_nonzero(mask))
    zone = Zone(name="background", mask=mask, pixels=pixels, sufficient=True)
    return Zones(zones={"background": zone})


def _flat(colour_rgb: tuple[int, int, int], size: int = 120) -> ImageArray:
    return np.full((size, size, 3), colour_rgb, dtype=np.uint8)


# --- the sclera estimate ----------------------------------------------------


def test_the_estimate_recovers_the_illuminant_it_was_given(config: EngineConfig) -> None:
    for name in ROOM_LIGHTS:
        white = _white(name)
        measured = _lit_by(TRUE_SCLERA, white)

        estimated = illuminant_from_sclera(_sample(measured), config)

        assert np.allclose(colour.XYZ_to_xy(estimated), ILLUMINANTS[name], atol=1e-3), name


def test_correcting_a_cast_returns_the_skin_it_started_from(config: EngineConfig) -> None:
    """The point of the whole step: the same face under five lamps measures as
    one colour again."""
    for name in ROOM_LIGHTS:
        white = _white(name)
        sclera = _lit_by(TRUE_SCLERA, white)
        skin = _lit_by(TRUE_SKIN, white)

        estimated = illuminant_from_sclera(_sample(sclera), config)
        corrected = adapt_sample(_sample(skin), estimated)

        assert _delta_e(_sample(skin), TRUE_SKIN) > 2.0, f"{name} is not a cast worth testing"
        assert _delta_e(corrected, TRUE_SKIN) < 0.5, name


def test_a_neutral_sclera_asks_for_no_correction(config: EngineConfig) -> None:
    estimated = illuminant_from_sclera(_sample(TRUE_SCLERA), config)

    assert np.allclose(estimated, D65_XYZ, atol=1e-6)


def test_the_target_is_what_the_sclera_is_corrected_to(config: EngineConfig) -> None:
    """A real sclera is slightly warm. Correcting it to exact neutral would push
    every photo cool by that much - a systematic error on the one axis the
    product is sold on."""
    warm_sclera = (88.0, 1.5, 5.0)
    to_neutral = illuminant_from_sclera(_sample(warm_sclera), config)
    to_itself = illuminant_from_sclera(
        _sample(warm_sclera), config.model_copy(update={"sclera_target_ab": (1.5, 5.0)})
    )

    assert not np.allclose(to_neutral, D65_XYZ, atol=1e-3), "a warm sclera should move something"
    assert np.allclose(to_itself, D65_XYZ, atol=1e-6), "at its own target, nothing should move"


def test_the_target_shifts_the_corrected_skin(config: EngineConfig) -> None:
    warm_sclera = (88.0, 1.5, 5.0)
    skin = _sample(TRUE_SKIN)

    neutral_target = adapt_sample(skin, illuminant_from_sclera(_sample(warm_sclera), config))
    own_target = adapt_sample(
        skin,
        illuminant_from_sclera(
            _sample(warm_sclera), config.model_copy(update={"sclera_target_ab": (1.5, 5.0)})
        ),
    )

    assert neutral_target.b < own_target.b, "correcting to pure white should cool the skin"


# --- is the sclera usable? --------------------------------------------------


def test_two_agreeing_eyes_are_the_reference(config: EngineConfig) -> None:
    estimate = assess_sclera(
        (_side("left", (88.0, 0.5, 2.0)), _side("right", (87.0, 0.0, 1.0))),
        config,
    )

    assert estimate.usable is True
    assert estimate.single_eye is False
    assert estimate.eye_distance is not None and estimate.eye_distance < 2.0


def test_eyes_that_disagree_are_not_a_reference(config: EngineConfig) -> None:
    """Both eyes see the same light. If they disagree, at least one mask is not
    on sclera, and there is no way to tell which."""
    estimate = assess_sclera(
        (_side("left", (88.0, 0.0, 2.0)), _side("right", (87.0, 12.0, 14.0))),
        config,
    )

    assert estimate.usable is False
    assert estimate.reason is ScleraReason.EYES_DISAGREE


def test_one_usable_eye_is_used_and_flagged(config: EngineConfig) -> None:
    estimate = assess_sclera(
        (
            _side("left", (88.0, 0.5, 2.0)),
            _side("right", None, reason=ScleraReason.TOO_FEW_PIXELS, pixels_after=3),
        ),
        config,
    )

    assert estimate.usable is True
    assert estimate.single_eye is True
    assert estimate.eye_distance is None


def test_a_bad_eye_does_not_take_the_good_one_with_it(config: EngineConfig) -> None:
    """Each eye is judged on its own first. The two would disagree wildly if
    they were compared - the point is that they are not, because only one of
    them is a measurement at all."""
    estimate = assess_sclera(
        (
            _side("left", (88.0, 0.5, 2.0)),
            _side("right", (87.0, 14.0, 16.0), reason=ScleraReason.TOO_NOISY),
        ),
        config,
    )

    assert estimate.usable is True
    assert estimate.single_eye is True
    assert estimate.reason is ScleraReason.OK
    assert estimate.eye_distance is None, "there was nothing to compare it against"


def test_an_eye_in_shadow_is_dropped_before_the_comparison(config: EngineConfig) -> None:
    """Brighter-than-skin is a per-eye verdict, reached in ``sampling`` before
    anything is pooled. Here it simply has to be honoured."""
    estimate = assess_sclera(
        (
            _side("left", (88.0, 0.5, 2.0)),
            _side("right", (71.0, 9.0, 11.0), reason=ScleraReason.DARKER_THAN_SKIN),
        ),
        config,
    )

    assert estimate.usable is True
    assert estimate.single_eye is True
    assert estimate.sample is not None
    assert estimate.sample.L == 88.0, "the good eye alone, not the average of the two"


def test_two_good_eyes_that_disagree_are_both_dropped(config: EngineConfig) -> None:
    """The strong test, deliberately unforgiving. Both masks look fine on their
    own and they disagree about the colour of the one light in the room, so one
    of them is not on sclera - and nothing in a single frame says which."""
    estimate = assess_sclera(
        (_side("left", (88.0, 0.0, 2.0)), _side("right", (87.0, 11.0, 13.0))),
        config,
    )

    assert estimate.usable is False
    assert estimate.reason is ScleraReason.EYES_DISAGREE
    assert estimate.eye_distance is not None
    assert estimate.eye_distance > config.sclera_eye_agreement_max_dab


def test_a_sclera_no_brighter_than_the_skin_is_not_a_sclera(config: EngineConfig) -> None:
    """The verdict is reached per eye in ``sampling`` - relative on purpose,
    because it holds for any skin tone at any exposure where 'the sclera is
    bright' does not - and it is the reason that comes back out."""
    reason = ScleraReason.DARKER_THAN_SKIN
    estimate = assess_sclera(
        (
            _side("left", (72.0, 0.5, 2.0), reason=reason),
            _side("right", (71.0, 0.0, 1.0), reason=reason),
        ),
        config,
    )

    assert estimate.usable is False
    assert estimate.reason is reason


def test_a_dark_frame_does_not_make_the_sclera_unusable(config: EngineConfig) -> None:
    """The case the old absolute L* threshold got wrong: underexposed, but the
    sclera is still clean and still far brighter than the skin."""
    estimate = assess_sclera(
        (_side("left", (44.0, 0.5, 2.0)), _side("right", (43.0, 0.0, 1.0))),
        config,
    )

    assert estimate.usable is True
    assert estimate.reason is ScleraReason.OK


def test_the_local_verdict_is_carried_through(config: EngineConfig) -> None:
    estimate = assess_sclera(
        (
            _side("left", (88.0, 0.0, 2.0), reason=ScleraReason.TOO_NOISY),
            _side("right", (88.0, 0.0, 2.0), reason=ScleraReason.TOO_NOISY),
        ),
        config,
    )

    assert estimate.usable is False
    assert estimate.reason is ScleraReason.TOO_NOISY


def test_no_eyes_at_all(config: EngineConfig) -> None:
    estimate = assess_sclera((), config)

    assert estimate.usable is False
    assert estimate.reason is ScleraReason.TOO_FEW_PIXELS


# --- the scene fallback -----------------------------------------------------


def test_a_varied_scene_under_a_neutral_light_estimates_near_d65(config: EngineConfig) -> None:
    rng = np.random.default_rng(4)
    image = rng.integers(40, 210, size=(160, 160, 3), dtype=np.uint8)

    estimate = estimate_from_scene(image, _scene_zones(image), config)

    assert estimate.usable is True
    assert estimate.white_xyz is not None
    assert np.allclose(colour.XYZ_to_xy(estimate.white_xyz), (0.3127, 0.329), atol=0.02)


def test_a_plain_white_wall_is_a_good_scene(config: EngineConfig) -> None:
    """Flat is not the problem. Flat *and* coloured is."""
    image = _flat((205, 204, 206), size=160)

    estimate = estimate_from_scene(image, _scene_zones(image), config)

    assert estimate.usable is True
    assert estimate.reason == "ok"


def test_a_plain_coloured_wall_is_refused(config: EngineConfig) -> None:
    """From one frame there is no telling a white wall under an orange lamp from
    an orange wall under a white one, so the estimate is not used."""
    image = _flat((210, 150, 110), size=160)

    estimate = estimate_from_scene(image, _scene_zones(image), config)

    assert estimate.usable is False
    assert estimate.reason == "uniform_coloured_surface"
    assert estimate.ab_spread is not None and estimate.ab_spread < config.scene_uniform_ab_spread


def test_a_blown_out_window_is_not_a_measurement_of_the_light(config: EngineConfig) -> None:
    image = _flat((255, 255, 255), size=160)

    estimate = estimate_from_scene(image, _scene_zones(image), config)

    assert estimate.usable is False
    assert estimate.reason == "too_few_pixels"


def test_a_dark_corner_is_not_a_measurement_of_the_light(config: EngineConfig) -> None:
    image = _flat((6, 6, 7), size=160)

    estimate = estimate_from_scene(image, _scene_zones(image), config)

    assert estimate.usable is False
    assert estimate.reason == "too_few_pixels"


def test_no_background_zone_at_all(config: EngineConfig) -> None:
    estimate = estimate_from_scene(_flat((128, 128, 128)), Zones(), config)

    assert estimate.usable is False
    assert estimate.pixels == 0


# --- the step as a whole ----------------------------------------------------


def test_the_sclera_is_preferred_and_the_samples_come_back_corrected(
    config: EngineConfig,
) -> None:
    white = _white("A")
    measured = _samples(
        skin=_lit_by(TRUE_SKIN, white),
        sclera=_lit_by(TRUE_SCLERA, white),
        hair=_lit_by(TRUE_HAIR, white),
    )
    sides = (
        _side("left", _lit_by(TRUE_SCLERA, white)),
        _side("right", _lit_by(TRUE_SCLERA, white)),
    )
    image = _flat((120, 120, 120))

    result = apply_white_balance(image, _scene_zones(image), measured, sides, config)

    assert result.info.method is WhiteBalanceMethod.SCLERA
    assert result.reject_reason is None
    assert result.samples.skin is not None
    assert _delta_e(result.samples.skin, TRUE_SKIN) < 0.5
    assert result.samples.hair is not None
    assert _delta_e(result.samples.hair, TRUE_HAIR) < 1.0
    assert result.uncorrected.skin is not None
    assert _delta_e(result.uncorrected.skin, TRUE_SKIN) > 2.0, "the raw measurement is kept"


def test_the_strength_and_temperature_describe_the_light(config: EngineConfig) -> None:
    white = _white("A")
    sides = (
        _side("left", _lit_by(TRUE_SCLERA, white)),
        _side("right", _lit_by(TRUE_SCLERA, white)),
    )
    image = _flat((120, 120, 120))

    result = apply_white_balance(
        image, _scene_zones(image), _samples(sclera=_lit_by(TRUE_SCLERA, white)), sides, config
    )

    # Illuminant A is a tungsten bulb: 2856 K, and 0.081 from D65 in u'v'.
    assert result.info.estimated_cct is not None
    assert 2700.0 < result.info.estimated_cct < 3000.0
    assert 0.075 < result.info.correction_strength < 0.085


def test_the_scene_takes_over_when_the_sclera_cannot(config: EngineConfig) -> None:
    rng = np.random.default_rng(7)
    image = rng.integers(40, 210, size=(160, 160, 3), dtype=np.uint8)
    blind = (_side("left", None, reason=ScleraReason.TOO_FEW_PIXELS),)

    result = apply_white_balance(image, _scene_zones(image), _samples(), blind, config)

    assert result.info.method is WhiteBalanceMethod.SCENE
    assert result.confidence_factor == config.confidence_factor_scene
    assert any("sclera unusable" in note for note in result.notes)


def test_a_strong_colour_wash_is_rejected_rather_than_corrected(config: EngineConfig) -> None:
    """A sodium lamp or a stage light does not light whole parts of the
    spectrum. There is nothing there to bring back."""
    white = _white_xy(COLOURED_WASH)
    sides = (
        _side("left", _lit_by(TRUE_SCLERA, white)),
        _side("right", _lit_by(TRUE_SCLERA, white)),
    )
    image = _flat((120, 120, 120))

    result = apply_white_balance(
        image, _scene_zones(image), _samples(sclera=_lit_by(TRUE_SCLERA, white)), sides, config
    )

    assert result.reject_reason is RejectReason.COLOR_CAST
    assert result.info.method is WhiteBalanceMethod.NONE
    assert result.info.correction_strength > config.max_correction_duv
    assert result.samples == result.uncorrected, "a refusal must not half-correct"


def test_a_tungsten_bulb_stays_on_the_correctable_side(config: EngineConfig) -> None:
    """The threshold has to sit above a room lamp, or every warm indoor photo is
    thrown away instead of measured."""
    white = _white("A")
    sides = (
        _side("left", _lit_by(TRUE_SCLERA, white)),
        _side("right", _lit_by(TRUE_SCLERA, white)),
    )
    image = _flat((120, 120, 120))

    result = apply_white_balance(
        image, _scene_zones(image), _samples(sclera=_lit_by(TRUE_SCLERA, white)), sides, config
    )

    assert result.reject_reason is None
    assert result.info.method is WhiteBalanceMethod.SCLERA


def test_with_no_estimate_and_no_visible_cast_the_samples_pass_through(
    config: EngineConfig,
) -> None:
    image = _flat((205, 204, 206))
    blind = (_side("left", None, reason=ScleraReason.TOO_FEW_PIXELS),)
    # A flat neutral wall is a usable scene, so blank the zone to get no
    # estimate at all.
    measured = _samples()

    result = apply_white_balance(image, Zones(), measured, blind, config)

    assert result.info.method is WhiteBalanceMethod.NONE
    assert result.reject_reason is None
    assert result.samples == measured
    assert result.confidence_factor == config.confidence_factor_no_estimate


def test_with_no_estimate_a_frame_where_everything_agrees_is_only_suspected(
    config: EngineConfig,
) -> None:
    """Suspected, never refused. A warm-toned person in front of a beige wall
    reads exactly like a warm room, so refusing here would fall on warm colour
    types systematically - a bias baked into the product, not a quality gate."""
    white = _white_xy(COLOURED_WASH)
    measured = _samples(
        skin=_lit_by(TRUE_SKIN, white),
        sclera=_lit_by(TRUE_SCLERA, white),
    )
    blind = (_side("left", None, reason=ScleraReason.TOO_FEW_PIXELS),)

    result = apply_white_balance(_flat((120, 120, 120)), Zones(), measured, blind, config)

    assert result.reject_reason is None
    assert result.suspicion == CAST_SUSPECTED
    assert result.confidence_factor == config.confidence_factor_cast_suspected
    assert any("consistently coloured" in note for note in result.notes)
    assert result.as_dict()["suspicion"] == CAST_SUSPECTED


def test_a_measured_illuminant_can_still_refuse_the_photo(config: EngineConfig) -> None:
    """The distinction that matters: a suspicion is not a measurement, and only
    a measurement refuses."""
    white = _white_xy(COLOURED_WASH)
    sides = (
        _side("left", _lit_by(TRUE_SCLERA, white)),
        _side("right", _lit_by(TRUE_SCLERA, white)),
    )
    image = _flat((120, 120, 120))

    result = apply_white_balance(
        image, _scene_zones(image), _samples(sclera=_lit_by(TRUE_SCLERA, white)), sides, config
    )

    assert result.reject_reason is RejectReason.COLOR_CAST
    assert result.suspicion is None


def test_warm_skin_on_its_own_is_not_a_cast(config: EngineConfig) -> None:
    """The false positive that matters: a warm-toned person is not a warm room.
    One chromatic zone is a chromatic thing, not a light."""
    measured = _samples(skin=(62.0, 22.0, 30.0), sclera=(86.0, 0.5, 2.0))
    blind = (_side("left", None, reason=ScleraReason.TOO_FEW_PIXELS),)

    result = apply_white_balance(_flat((120, 120, 120)), Zones(), measured, blind, config)

    assert result.reject_reason is None
    assert result.suspicion is None
    assert result.info.method is WhiteBalanceMethod.NONE


# --- the preview ------------------------------------------------------------


def test_the_preview_image_is_corrected_the_same_way(config: EngineConfig) -> None:
    """Preview only - nothing is measured off it - but it has to agree with the
    numbers, or the report lies to whoever is checking them."""
    white = _white("A")
    patch = colour.XYZ_to_sRGB(
        colour.Lab_to_XYZ(np.asarray(_lit_by(TRUE_SKIN, white), dtype=np.float64))
    )
    image = np.full((40, 40, 3), np.clip(patch, 0.0, 1.0) * 255.0, dtype=np.uint8)

    corrected = adapt_image(image, white)

    measured = colour.XYZ_to_Lab(colour.sRGB_to_XYZ(corrected[20, 20] / 255.0))
    assert _delta_e(_sample(tuple(float(v) for v in measured)), TRUE_SKIN) < 2.0  # type: ignore[arg-type]


def test_the_preview_keeps_the_frame_shape(config: EngineConfig) -> None:
    image = _flat((180, 140, 100), size=64)
    cv2.circle(image, (32, 32), 10, (40, 60, 90), thickness=-1)

    corrected = adapt_image(image, _white("A"))

    assert corrected.shape == image.shape
    assert corrected.dtype == np.uint8


# --- the off switch and the calibration flag --------------------------------


def test_switching_white_balance_off_reports_the_samples_untouched(
    config: EngineConfig,
) -> None:
    """The shape a deployment takes before sclera_target_ab has been measured:
    no correction rather than an uncalibrated one."""
    white = _white("A")
    measured = _samples(skin=_lit_by(TRUE_SKIN, white), sclera=_lit_by(TRUE_SCLERA, white))
    sides = (
        _side("left", _lit_by(TRUE_SCLERA, white)),
        _side("right", _lit_by(TRUE_SCLERA, white)),
    )
    image = _flat((120, 120, 120))
    off = config.model_copy(update={"white_balance_enabled": False})

    result = apply_white_balance(image, _scene_zones(image), measured, sides, off)

    assert result.info.method is WhiteBalanceMethod.NONE
    assert result.applied is False
    assert result.samples == measured
    assert result.reject_reason is None
    assert result.confidence_factor == off.confidence_factor_no_estimate
    assert any("switched off" in note for note in result.notes)


def test_the_scene_is_still_measured_with_white_balance_off(config: EngineConfig) -> None:
    """The quality gate reads the scene's exposure to tell a dark photo from
    dark skin, and that question does not go away with the correction."""
    rng = np.random.default_rng(11)
    image = rng.integers(40, 210, size=(160, 160, 3), dtype=np.uint8)
    off = config.model_copy(update={"white_balance_enabled": False})

    result = apply_white_balance(image, _scene_zones(image), _samples(), (), off)

    assert result.scene is not None
    assert result.scene.median_L is not None


def test_a_strong_cast_is_not_refused_when_the_step_is_off(config: EngineConfig) -> None:
    """Off means off. Nothing is estimated, so nothing can be judged too far."""
    white = _white_xy(COLOURED_WASH)
    sides = (
        _side("left", _lit_by(TRUE_SCLERA, white)),
        _side("right", _lit_by(TRUE_SCLERA, white)),
    )
    image = _flat((120, 120, 120))
    off = config.model_copy(update={"white_balance_enabled": False})

    result = apply_white_balance(
        image, _scene_zones(image), _samples(sclera=_lit_by(TRUE_SCLERA, white)), sides, off
    )

    assert result.reject_reason is None
    assert result.info.correction_strength == 0.0


def test_every_result_says_whether_the_target_is_calibrated(config: EngineConfig) -> None:
    """Including the ones that corrected nothing: the target is a guess whatever
    else the photo did."""
    white = _white("A")
    sides = (
        _side("left", _lit_by(TRUE_SCLERA, white)),
        _side("right", _lit_by(TRUE_SCLERA, white)),
    )
    image = _flat((120, 120, 120))
    measured = _samples(sclera=_lit_by(TRUE_SCLERA, white))
    calibrated = config.model_copy(update={"sclera_target_calibrated": True})

    assert (
        apply_white_balance(
            image, _scene_zones(image), measured, sides, config
        ).info.target_calibrated
        is False
    )
    assert (
        apply_white_balance(
            image, _scene_zones(image), measured, sides, calibrated
        ).info.target_calibrated
        is True
    )
    assert (
        apply_white_balance(image, Zones(), _samples(), (), config).info.target_calibrated is False
    )


def test_the_shipped_default_is_off() -> None:
    """A caller who has not thought about calibration gets no correction and a
    lowered confidence, rather than a confident wrong answer. Development turns
    it on with COLOR_ENGINE_WHITE_BALANCE_ENABLED=true."""
    assert EngineConfig().white_balance_enabled is False
    assert EngineConfig().sclera_target_calibrated is False


# --- "did it run, and if not, why not" -------------------------------------


def test_the_contract_says_white_balance_was_switched_off(config: EngineConfig) -> None:
    # `method: none` on its own covers three different situations, only one of
    # which is about the photograph. A page that cannot tell them apart sends
    # somebody off to retake a frame that was fine.
    off = config.model_copy(update={"white_balance_enabled": False})

    info = white_balance_info(off)

    assert info.enabled is False
    assert info.reason is WhiteBalanceReason.DISABLED


def test_a_correction_that_happened_needs_no_excuse(config: EngineConfig) -> None:
    on = config.model_copy(update={"white_balance_enabled": True})

    info = white_balance_info(on, method=WhiteBalanceMethod.SCLERA)

    assert info.enabled is True
    assert info.reason is None


def test_an_enabled_run_that_found_no_reference_says_which(config: EngineConfig) -> None:
    on = config.model_copy(update={"white_balance_enabled": True})

    info = white_balance_info(on, reason=WhiteBalanceReason.NO_SCENE)

    assert info.enabled is True
    assert info.reason is WhiteBalanceReason.NO_SCENE


def test_switched_off_beats_any_other_reason(config: EngineConfig) -> None:
    # Nothing was attempted, so nothing about the photograph can be the reason.
    off = config.model_copy(update={"white_balance_enabled": False})

    info = white_balance_info(off, reason=WhiteBalanceReason.NO_SCLERA)

    assert info.reason is WhiteBalanceReason.DISABLED


def test_the_correction_carries_every_sample_including_the_per_side_ones() -> None:
    """A corrected photo used to reach the contract with each eye's own numbers
    gone.

    ``adapt_samples`` rebuilt ``Samples`` from a hand-written list of fields, and
    the two it did not list defaulted to empty. Nothing complained: the contract
    allows them to be empty, and the only frames anybody had looked at closely
    were ones where no correction was applied.
    """
    eye = LabSample(L=88.0, a=-0.5, b=3.0, C=3.04, h=99.5, pixels=400, L_iqr=1.0)
    iris = LabSample(L=36.9, a=-3.1, b=-1.3, C=3.4, h=202.4, pixels=290, L_iqr=8.6)
    samples = Samples(
        skin=eye,
        iris=iris,
        iris_parts=EyeParts(left=iris, right=iris),
        sclera=eye,
        sclera_parts=EyeParts(left=eye, right=eye),
    )

    corrected = adapt_samples(samples, np.array([0.96, 1.0, 0.86]))

    for group in (corrected.iris_parts, corrected.sclera_parts):
        assert group.left is not None and group.right is not None
    # Adapted, not merely copied through.
    assert corrected.sclera_parts.left is not None
    assert corrected.sclera_parts.left.b != eye.b
