"""Is this sample really the white of the eye?

The old answer was one test - brighter than the skin by a margin - and it was
wrong at both ends of the skin-tone axis. Unsatisfiable on very light or
overexposed skin: the first live frame measured skin at L* 86.9 and refused both
eyes, because nothing in an eye socket is 5 L* above that. Far too easy on deep
skin, where almost anything in the socket clears L* 35, including the lid shadow
the test existed to reject.

What these check is that the replacement works at both ends and still catches
what it was always for.
"""

from __future__ import annotations

import math

import numpy as np
import pytest

from color_engine.config import EngineConfig
from color_engine.models import LabSample
from color_engine.sampling import (
    ScleraQuality,
    ScleraReason,
    ScleraSelection,
    _judged,
    _under_skin_tolerance,
)


def _sample(lightness: float, a: float, b: float) -> LabSample:
    return LabSample(
        L=lightness,
        a=a,
        b=b,
        C=math.hypot(a, b),
        h=math.degrees(math.atan2(b, a)) % 360.0,
        pixels=400,
        L_iqr=2.0,
    )


def _picked(sample: LabSample) -> ScleraSelection:
    quality = ScleraQuality(
        pixels_before=400,
        pixels_after=400,
        specular_pixels=0,
        median_L=sample.L,
        median_C=sample.C,
        reason=ScleraReason.OK,
    )

    return ScleraSelection(
        lab=np.zeros((0, 3), dtype=np.float64), sample=sample, quality=quality, kept=None
    )


def _verdict(
    sclera: LabSample,
    skin: LabSample | None,
    iris: LabSample | None,
    config: EngineConfig,
) -> ScleraReason:
    return _judged(_picked(sclera), skin, iris, config).quality.reason


# The three faces these tests are about.
VERY_LIGHT_SKIN = _sample(86.9, 7.5, 6.4)  # the first live frame, to the decimal
DEEP_SKIN = _sample(32.0, 12.0, 16.0)
MID_SKIN = _sample(66.0, 13.0, 18.0)

PALE_IRIS = _sample(62.0, -4.0, -6.0)
DARK_IRIS = _sample(24.0, 3.0, 4.0)


def test_a_normal_sclera_on_very_light_skin_is_usable(config: EngineConfig) -> None:
    # THE regression. Under the old test this was refused, because a sclera
    # cannot be 5 L* above skin that is already at L* 86.9.
    sclera = _sample(88.0, -0.5, 3.0)

    assert _verdict(sclera, VERY_LIGHT_SKIN, PALE_IRIS, config) is ScleraReason.OK


def test_a_sclera_slightly_darker_than_very_light_skin_is_still_usable(
    config: EngineConfig,
) -> None:
    # Fair skin can genuinely out-read the white of the eye by a few L*.
    sclera = _sample(83.0, -0.5, 3.0)

    assert _verdict(sclera, VERY_LIGHT_SKIN, PALE_IRIS, config) is ScleraReason.OK


def test_a_normal_sclera_on_deep_skin_is_usable(config: EngineConfig) -> None:
    sclera = _sample(72.0, 0.5, 4.0)

    assert _verdict(sclera, DEEP_SKIN, DARK_IRIS, config) is ScleraReason.OK


def test_lid_shadow_on_deep_skin_is_rejected(config: EngineConfig) -> None:
    # The case the old test waved through: deep shadow that still clears the
    # skin's own lightness, so "brighter than skin" said yes.
    shadow = _sample(28.0, 8.0, 11.0)

    assert _verdict(shadow, DEEP_SKIN, DARK_IRIS, config) is not ScleraReason.OK


def test_lid_shadow_on_light_skin_is_rejected(config: EngineConfig) -> None:
    shadow = _sample(55.0, 9.0, 12.0)

    assert _verdict(shadow, VERY_LIGHT_SKIN, PALE_IRIS, config) is not ScleraReason.OK


def test_a_sample_that_landed_on_skin_is_rejected_by_its_chroma(
    config: EngineConfig,
) -> None:
    # Not dark, not dim - it IS the skin, and the only thing that says so is the
    # chroma it carries. A tolerance-shaped test would wave this through.
    on_skin = _sample(66.0, 13.0, 18.0)

    assert _verdict(on_skin, MID_SKIN, DARK_IRIS, config) is (ScleraReason.MORE_CHROMATIC_THAN_SKIN)


def test_a_sample_no_lighter_than_its_own_iris_is_rejected(config: EngineConfig) -> None:
    # The white is the lightest thing in an eye, whatever the face around it.
    assert _verdict(_sample(63.0, -0.5, 2.0), MID_SKIN, PALE_IRIS, config) is (
        ScleraReason.NOT_BRIGHTER_THAN_IRIS
    )


def test_the_iris_test_leaves_room_for_a_pale_eye(config: EngineConfig) -> None:
    # A grey iris at L* 62 with a sclera at 88 is an ordinary pale eye, and the
    # margin must not be so wide that it refuses one.
    assert _verdict(_sample(88.0, -0.5, 3.0), MID_SKIN, PALE_IRIS, config) is ScleraReason.OK


def test_without_an_iris_the_other_two_tests_still_run(config: EngineConfig) -> None:
    good = _sample(88.0, -0.5, 3.0)
    on_skin = _sample(66.0, 13.0, 18.0)

    assert _verdict(good, MID_SKIN, None, config) is ScleraReason.OK
    assert _verdict(on_skin, MID_SKIN, None, config) is ScleraReason.MORE_CHROMATIC_THAN_SKIN


def test_without_an_iris_a_shallow_shadow_can_slip_through(config: EngineConfig) -> None:
    # Documented, not desirable. With no iris the strong test is gone, and a
    # near-neutral shadow within the skin tolerance has nothing left to catch
    # it. The alternative - refusing every sclera whose iris is missing - throws
    # away good measurements to avoid a rare bad one.
    shallow = _sample(62.0, 0.0, 2.0)

    assert _verdict(shallow, MID_SKIN, None, config) is ScleraReason.OK
    assert _verdict(shallow, MID_SKIN, PALE_IRIS, config) is ScleraReason.NOT_BRIGHTER_THAN_IRIS


def test_the_chroma_floor_keeps_the_test_satisfiable_on_pale_skin(
    config: EngineConfig,
) -> None:
    # Skin with almost no chroma of its own would otherwise demand an
    # impossibly neutral sclera - and low-chroma skin is exactly the case the
    # rewrite exists for.
    pale_flat_skin = _sample(84.0, 3.0, 3.0)
    ordinary_sclera = _sample(86.0, -0.5, 4.0)

    assert pale_flat_skin.C * config.sclera_chroma_ratio < ordinary_sclera.C
    assert _verdict(ordinary_sclera, pale_flat_skin, PALE_IRIS, config) is ScleraReason.OK


def test_no_skin_at_all_leaves_only_the_iris_test(config: EngineConfig) -> None:
    assert _verdict(_sample(88.0, -0.5, 3.0), None, PALE_IRIS, config) is ScleraReason.OK
    assert _verdict(_sample(63.0, -0.5, 2.0), None, PALE_IRIS, config) is (
        ScleraReason.NOT_BRIGHTER_THAN_IRIS
    )


# --- how far under the skin a sclera may read -----------------------------
#
# The second live frame. Skin at L* 84.8, both irises measured at L* 32.7 and
# L* 38.2, and both eyes refused as darker_than_skin - a verdict that says "lid
# shadow" about a sample clearing its own iris by forty-odd L*. A constant
# tolerance in L* is not a constant amount of shade: the same 6 L* is a shadow
# of 0.67 under deep skin and 0.83 under fair skin, so the test was at its
# strictest exactly where the eye socket sits furthest below the cheek.

LIVE_LIGHT_SKIN = _sample(84.831, 10.771, 6.894)  # the second live frame
LIVE_IRIS = _sample(36.879, -3.142, -1.296)


def test_a_sclera_well_under_very_light_skin_is_usable(config: EngineConfig) -> None:
    # THE regression. L* 70 against skin at L* 85 is 15 L* under - refused by
    # the constant 6, and ordinary for an eye in a socket under a brow.
    sclera = _sample(70.0, -0.5, 3.0)

    assert _verdict(sclera, _sample(85.0, 7.5, 6.4), DARK_IRIS, config) is ScleraReason.OK


def test_the_second_live_frame_is_no_longer_refused(config: EngineConfig) -> None:
    # Its own numbers, to the decimal, with a sclera where one plausibly sits.
    assert _verdict(_sample(70.0, -0.5, 3.0), LIVE_LIGHT_SKIN, LIVE_IRIS, config) is (
        ScleraReason.OK
    )


def test_lid_shadow_on_very_light_skin_is_still_rejected(config: EngineConfig) -> None:
    # The case the loosening must not swallow, and the only test that can catch
    # it here: it is 18 L* above a dark iris, so (1) passes it, and it is nearly
    # neutral, so (3) passes it too.
    shadow = _sample(55.0, 1.5, 2.0)

    assert _verdict(shadow, _sample(85.0, 7.5, 6.4), LIVE_IRIS, config) is (
        ScleraReason.DARKER_THAN_SKIN
    )


def test_the_tolerance_grows_with_the_lightness_of_the_skin(config: EngineConfig) -> None:
    # The shape of it, stated directly rather than inferred from verdicts.
    deep = _under_skin_tolerance(32.0, config)
    mid = _under_skin_tolerance(66.0, config)
    light = _under_skin_tolerance(85.0, config)

    assert deep < mid < light
    assert deep >= config.sclera_under_skin_max_l


def test_the_constant_is_a_floor_and_almost_never_the_answer(config: EngineConfig) -> None:
    # Said plainly, because the comfortable version of this change - "deep skin
    # is untouched" - is not true. At 0.55 the ratio asks for less than 6 L*
    # only below about L* 17, which is darker than skin gets: EVERY skin tone
    # is loosened. The floor is kept for the bottom of the range, where the
    # cube root stops describing CIELAB.
    assert _under_skin_tolerance(32.0, config) > config.sclera_under_skin_max_l
    assert _under_skin_tolerance(10.0, config) == config.sclera_under_skin_max_l


def test_the_tolerance_is_a_constant_ratio_of_luminance(config: EngineConfig) -> None:
    # What it means: whatever the skin tone, the sclera may read down to the
    # same FRACTION of the skin's luminance. Recovered from the tolerance by
    # inverting CIELAB's cube root.
    for skin_lightness in (55.0, 70.0, 85.0):
        floor = skin_lightness - _under_skin_tolerance(skin_lightness, config)
        ratio = ((floor + 16.0) / (skin_lightness + 16.0)) ** 3
        assert ratio == pytest.approx(config.sclera_socket_light_ratio, abs=1e-6)


def test_deep_skin_keeps_the_verdicts_it_had(config: EngineConfig) -> None:
    # Its tolerance does move - 6 L* to 8.7 at L* 32 - but nothing it decides
    # does, because what catches lid shadow on a deep-skinned face is the iris
    # test and the chroma test, and neither changed.
    assert _verdict(_sample(72.0, 0.5, 4.0), DEEP_SKIN, DARK_IRIS, config) is ScleraReason.OK
    assert _verdict(_sample(28.0, 8.0, 11.0), DEEP_SKIN, DARK_IRIS, config) is not ScleraReason.OK
    # 10 L* under skin at L* 32, neutral enough to clear the chroma test and
    # above the iris: refused before, refused now.
    assert _verdict(_sample(22.0, 1.0, 2.0), DEEP_SKIN, _sample(12.0, 1.0, 1.0), config) is (
        ScleraReason.DARKER_THAN_SKIN
    )


def test_without_an_iris_the_skin_tolerance_stays_strict(config: EngineConfig) -> None:
    # The loosening is paid for by the iris test. With no iris there is nothing
    # to lean on, so the old constant is the whole of the evidence outside the
    # eye - and the sclera that passes with an iris is refused without one.
    sclera = _sample(70.0, -0.5, 3.0)
    light_skin = _sample(85.0, 7.5, 6.4)

    assert _verdict(sclera, light_skin, DARK_IRIS, config) is ScleraReason.OK
    assert _verdict(sclera, light_skin, None, config) is ScleraReason.DARKER_THAN_SKIN


def test_the_loosening_does_not_touch_the_chroma_test(config: EngineConfig) -> None:
    # A sample on the lower lid: light enough for the widened tolerance, above
    # the iris, and carrying the skin's own colour. Chroma is what catches it,
    # exactly as before.
    on_lid_skin = _sample(78.0, 10.0, 6.5)

    assert _verdict(on_lid_skin, LIVE_LIGHT_SKIN, LIVE_IRIS, config) is (
        ScleraReason.MORE_CHROMATIC_THAN_SKIN
    )


def test_an_already_rejected_eye_keeps_its_first_reason(config: EngineConfig) -> None:
    # These tests run last. An eye that failed on pixel count should not be
    # relabelled with a colour complaint about a sample nobody trusts.
    quality = ScleraQuality(
        pixels_before=400,
        pixels_after=3,
        specular_pixels=0,
        median_L=66.0,
        median_C=22.0,
        reason=ScleraReason.TOO_FEW_PIXELS,
    )
    picked = ScleraSelection(
        lab=np.zeros((0, 3), dtype=np.float64),
        sample=_sample(66.0, 13.0, 18.0),
        quality=quality,
        kept=None,
    )

    assert _judged(picked, MID_SKIN, DARK_IRIS, config).quality.reason is (
        ScleraReason.TOO_FEW_PIXELS
    )
