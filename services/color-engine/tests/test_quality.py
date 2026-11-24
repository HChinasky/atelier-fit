"""The quality gate, and the one mistake it must not make.

Deep skin under good light measures like light skin in the dark. Rejecting the
first as ``low_light`` would refuse someone's photo because of their
complexion, so the tests that matter most here are the ones that prove it does
not.
"""

from __future__ import annotations

import numpy as np

from color_engine.config import EngineConfig
from color_engine.models import LabSample, RejectReason, Samples, SkinParts, WhiteBalance
from color_engine.quality import quality_gate
from color_engine.sampling import Measurements, ScleraQuality, ScleraReason, ScleraSide
from color_engine.white_balance import SceneEstimate, WhiteBalanceResult


def _sample(lightness: float, a: float = 12.0, b: float = 14.0, iqr: float = 4.0) -> LabSample:
    return LabSample(
        L=lightness,
        a=a,
        b=b,
        C=round(float(np.hypot(a, b)), 3),
        h=0.0,
        pixels=1200,
        L_iqr=iqr,
    )


def _measured(
    skin_l: float = 70.0,
    *,
    shadow: float | None = 0.05,
    cheek_iqr: float = 4.0,
    sclera_l: float | None = 85.0,
) -> Measurements:
    skin = _sample(skin_l, iqr=cheek_iqr)
    sides: tuple[ScleraSide, ...] = ()
    if sclera_l is not None:
        sides = (
            ScleraSide(
                side="left",
                sample=_sample(sclera_l, a=0.5, b=2.0),
                quality=ScleraQuality(400, 200, 0, sclera_l, 2.0, 2.0, 2.0, ScleraReason.OK),
            ),
        )
    return Measurements(
        samples=Samples(
            skin=skin,
            skin_parts=SkinParts(left_cheek=skin, right_cheek=skin),
        ),
        sclera_sides=sides,
        skin_shadow_fraction=shadow,
    )


def _balanced(scene_l: float | None = 55.0) -> WhiteBalanceResult:
    scene = (
        None
        if scene_l is None
        else SceneEstimate(pixels=9000, reason="ok", median_L=scene_l, ab_spread=9.0)
    )
    empty = Samples()
    return WhiteBalanceResult(samples=empty, info=WhiteBalance(), uncorrected=empty, scene=scene)


def test_a_well_lit_face_passes(config: EngineConfig) -> None:
    verdict = quality_gate(_measured(), _balanced(), config)

    assert verdict.reject_reason is None
    assert verdict.confidence_factor == 1.0


def test_no_skin_is_the_one_measurement_with_no_fallback(config: EngineConfig) -> None:
    measured = Measurements(samples=Samples())

    verdict = quality_gate(measured, _balanced(), config)

    assert verdict.reject_reason is RejectReason.INSUFFICIENT_SKIN


def test_dark_skin_under_good_light_is_not_low_light(config: EngineConfig) -> None:
    """The test this whole function exists for. Skin at L* 24 with a bright
    sclera and an exposed background is a dark-skinned person photographed
    well, and refusing it would be refusing her complexion."""
    verdict = quality_gate(_measured(skin_l=24.0, sclera_l=78.0), _balanced(55.0), config)

    assert verdict.reject_reason is None
    assert verdict.confidence_factor == 1.0
    assert any("the light was there" in note for note in verdict.notes)


def test_the_sclera_alone_is_enough_to_clear_a_dark_reading(config: EngineConfig) -> None:
    """Sclera reflectance barely varies between people, which makes it the
    better of the two answers - and it works with no background at all."""
    verdict = quality_gate(_measured(skin_l=22.0, sclera_l=72.0), _balanced(None), config)

    assert verdict.reject_reason is None


def test_the_scene_alone_is_enough_to_clear_a_dark_reading(config: EngineConfig) -> None:
    verdict = quality_gate(_measured(skin_l=22.0, sclera_l=None), _balanced(58.0), config)

    assert verdict.reject_reason is None
    assert any("the frame is exposed" in note for note in verdict.notes)


def test_a_genuinely_dark_frame_is_rejected(config: EngineConfig) -> None:
    """Everything is dark: the skin, the eyes and the room."""
    verdict = quality_gate(_measured(skin_l=20.0, sclera_l=28.0), _balanced(14.0), config)

    assert verdict.reject_reason is RejectReason.LOW_LIGHT
    assert verdict.signals["skin_L"] == 20.0
    assert verdict.signals["sclera_L"] == 28.0
    assert verdict.signals["scene_L"] == 14.0


def test_half_a_face_in_shadow_raises_the_question_too(config: EngineConfig) -> None:
    verdict = quality_gate(
        _measured(skin_l=60.0, shadow=0.55, sclera_l=30.0), _balanced(12.0), config
    )

    assert verdict.reject_reason is RejectReason.LOW_LIGHT
    assert any("sits in shadow" in note for note in verdict.notes)


def test_a_noisy_cheek_raises_the_question_too(config: EngineConfig) -> None:
    verdict = quality_gate(
        _measured(skin_l=60.0, cheek_iqr=22.0, sclera_l=26.0), _balanced(10.0), config
    )

    assert verdict.reject_reason is RejectReason.LOW_LIGHT
    assert any("noisy" in note for note in verdict.notes)


def test_shadow_and_noise_alone_do_not_reject_a_lit_frame(config: EngineConfig) -> None:
    verdict = quality_gate(
        _measured(skin_l=60.0, shadow=0.55, cheek_iqr=22.0, sclera_l=80.0), _balanced(60.0), config
    )

    assert verdict.reject_reason is None


def test_with_nothing_to_compare_against_the_photo_is_kept_and_trusted_less(
    config: EngineConfig,
) -> None:
    """No sclera, no background. Refusing on a signal we cannot interpret is the
    one outcome that is certainly wrong."""
    verdict = quality_gate(_measured(skin_l=20.0, sclera_l=None), _balanced(None), config)

    assert verdict.reject_reason is None
    assert verdict.confidence_factor == config.confidence_factor_exposure_unknown
    assert any("tell a dark photo from dark skin" in note for note in verdict.notes)


def test_the_verdict_carries_what_it_was_decided_on(config: EngineConfig) -> None:
    verdict = quality_gate(_measured(skin_l=24.0, sclera_l=78.0), _balanced(55.0), config)

    payload = verdict.as_dict()
    assert payload["reject_reason"] is None
    assert set(payload["signals"]) >= {
        "skin_L",
        "skin_shadow_fraction",
        "cheek_L_iqr",
        "sclera_L",
        "scene_L",
    }


def test_makeup_filter_is_declared_and_never_raised() -> None:
    """It stays in the contract because Laravel maps it to copy; no code path
    produces it, and none is planned. See the module docstring."""
    import inspect

    from color_engine import quality

    source = inspect.getsource(quality)

    assert "MAKEUP_FILTER" not in source, "nothing may raise it"
    assert RejectReason.MAKEUP_FILTER.value == "makeup_filter", "but the contract keeps it"
    assert "makeup_filter" in (quality.__doc__ or ""), "and the module says why"
