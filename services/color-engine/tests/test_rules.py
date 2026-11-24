"""Replaying today's thresholds over numbers measured earlier.

Two things have to be true of this or it is worse than useless.

It has to AGREE with the engine on the same input: a checker that answers
differently from the thing it checks tells you about itself. Every test here
that matters asserts a verdict the engine's own code would reach.

And it has to be honest about what it cannot see. The photograph is gone by the
time anybody asks, so a rule that read the pixels cannot be re-run. Saying
"usable" about a zone rejected for something invisible here would be the exact
failure that makes a tool like this dangerous.
"""

from __future__ import annotations

import math

import pytest

from color_engine.config import EngineConfig
from color_engine.models import EyeParts, LabSample, Samples, SkinParts
from color_engine.rules import (
    CLIPPING_BLIND_SPOT,
    NO_USABLE_EYE,
    PIXEL_REASONS,
    REPLAYABLE_REASONS,
    SPREAD_BLIND_SPOT,
    replay_rules,
)
from color_engine.sampling import ScleraReason, sample_zones, zone_statuses
from color_engine.zones import build_zones

from .test_sclera_pooling import _face
from .test_zones import _synthetic_detection


def _sample(lightness: float, a: float, b: float, pixels: int = 400) -> LabSample:
    return LabSample(
        L=lightness,
        a=a,
        b=b,
        C=math.hypot(a, b),
        h=math.degrees(math.atan2(b, a)) % 360.0,
        pixels=pixels,
        L_iqr=2.0,
    )


# The second live frame, to the decimal: skin pooled from two sub-zones because
# the third was blown, two dark irises, and two scleras that were refused.
LIVE_SKIN = _sample(84.831, 10.771, 6.894, pixels=12944)
LIVE_LEFT_CHEEK = _sample(82.784, 14.032, 7.393, pixels=1161)
LIVE_RIGHT_CHEEK = _sample(91.85, 2.187, 0.357, pixels=1163)
LIVE_FOREHEAD = _sample(86.877, 7.51, 6.395, pixels=11783)
LIVE_IRIS_LEFT = _sample(32.676, -2.343, -1.312, pixels=150)
LIVE_IRIS_RIGHT = _sample(38.201, -3.994, -1.2, pixels=140)


def _live(sclera_left: LabSample | None = None, sclera_right: LabSample | None = None) -> Samples:
    return Samples(
        skin=LIVE_SKIN,
        skin_parts=SkinParts(
            left_cheek=LIVE_LEFT_CHEEK,
            right_cheek=LIVE_RIGHT_CHEEK,
            forehead=LIVE_FOREHEAD,
        ),
        hair=_sample(23.696, 5.906, 23.22, pixels=16006),
        iris=_sample(36.879, -3.142, -1.296, pixels=290),
        iris_parts=EyeParts(left=LIVE_IRIS_LEFT, right=LIVE_IRIS_RIGHT),
        sclera=None,
        sclera_parts=EyeParts(left=sclera_left, right=sclera_right),
    )


def test_the_blown_cheek_replays_as_blown(config: EngineConfig) -> None:
    # The one zone on this frame whose rejection is arithmetic on the samples:
    # L* 91.9 with C* 2.2 is the "light and flat" signal, and it fires here
    # without needing to count a single clipped pixel.
    replays = replay_rules(_live(), config)

    cheek = replays["right_cheek"]
    assert cheek.usable is False
    assert cheek.reason == "overexposed"
    assert cheek.decided_by == "light_and_flat"


def test_a_cheek_that_is_merely_light_is_not_blown(config: EngineConfig) -> None:
    replays = replay_rules(_live(), config)

    assert replays["left_cheek"].usable is True
    assert replays["forehead"].usable is True
    assert replays["hair"].usable is True


def test_the_clipping_half_of_overexposure_is_declared_missing(config: EngineConfig) -> None:
    # A zone caught only by the clipped-fraction count replays as usable, and
    # the caller must be able to tell that from a clean pass.
    replays = replay_rules(_live(), config)

    assert CLIPPING_BLIND_SPOT in replays["forehead"].blind_spots
    assert CLIPPING_BLIND_SPOT in replays["right_cheek"].blind_spots
    # And the sclera has its own: `too_noisy` measured a pixel cloud nobody
    # kept, so a sclera rejected for spread replays as clean.
    with_eyes = replay_rules(_live(_sample(70.0, -0.5, 3.0)), config)
    assert SPREAD_BLIND_SPOT in with_eyes["sclera_left"].blind_spots


def test_a_zone_with_no_numbers_is_not_judged(config: EngineConfig) -> None:
    # Absent, not rejected. Whatever happened to it happened before any of
    # these rules ran, and inventing a verdict would be a lie with a number
    # beside it.
    replays = replay_rules(_live(), config)

    assert replays["sclera_left"].usable is None
    assert replays["sclera_left"].replayed is False
    assert replays["sclera_left"].reason is None


def test_a_sclera_the_old_threshold_refused_now_passes(config: EngineConfig) -> None:
    # The whole point of the command. The same numbers, two sets of thresholds,
    # and the answer changes - which is the thing that used to need the photo.
    sclera = _sample(70.0, -0.5, 3.0)
    replays = replay_rules(_live(sclera, sclera), config)

    assert replays["sclera_left"].usable is True
    strict = config.model_copy(update={"sclera_socket_light_ratio": 0.99})
    before = replay_rules(_live(sclera, sclera), strict)
    assert before["sclera_left"].usable is False
    assert before["sclera_left"].reason == "darker_than_skin"


def test_the_margin_says_by_how_much(config: EngineConfig) -> None:
    # "Rejected" is not reviewable; "rejected by 0.4" and "rejected by 18" are
    # different problems. Skin at L* 84.8 against a sclera at L* 60 is 24.8
    # under, on a tolerance of 18.2.
    replays = replay_rules(_live(_sample(60.0, -0.5, 3.0)), config)

    check = next(c for c in replays["sclera_left"].checks if c.name == "not_darker_than_skin")
    assert check.passed is False
    assert check.value == pytest.approx(24.831, abs=1e-3)
    assert check.margin == pytest.approx(-6.6, abs=0.1)


def test_a_passing_check_has_a_positive_margin(config: EngineConfig) -> None:
    replays = replay_rules(_live(_sample(70.0, -0.5, 3.0)), config)

    for check in replays["sclera_left"].checks:
        assert check.passed is True
        assert check.margin > 0, check.name


def test_the_first_failure_is_the_one_reported(config: EngineConfig) -> None:
    # Order matters: the engine tests the iris before the skin, so a sample
    # that fails both is a not_brighter_than_iris, and sending a reviewer to
    # the skin tolerance instead would waste their time.
    on_the_iris = _sample(35.0, -0.4, 2.0)
    replays = replay_rules(_live(on_the_iris), config)

    left = replays["sclera_left"]
    assert left.reason == "not_brighter_than_iris"
    assert left.decided_by == "brighter_than_iris"
    assert [c.passed for c in left.checks if c.name == "not_darker_than_skin"] == [False]


def test_two_eyes_that_disagree_take_the_pool_down(config: EngineConfig) -> None:
    # Each neutral enough to pass its own chroma test, and 8.5 apart in a*b* -
    # which is more than two eyes under one light can honestly be.
    left = _sample(70.0, -3.0, 3.0)
    right = _sample(70.0, 3.0, -3.0)
    replays = replay_rules(_live(left, right), config)

    assert replays["sclera_left"].usable is True
    assert replays["sclera_right"].usable is True
    assert replays["sclera"].usable is False
    assert replays["sclera"].reason == "eyes_disagree"


def test_one_surviving_eye_is_a_pool_of_one(config: EngineConfig) -> None:
    good = _sample(70.0, -0.5, 3.0)
    shadow = _sample(35.0, -0.4, 2.0)
    replays = replay_rules(_live(good, shadow), config)

    assert replays["sclera_right"].usable is False
    assert replays["sclera"].usable is True


def test_no_surviving_eye_leaves_the_pool_with_nothing_to_judge(config: EngineConfig) -> None:
    shadow = _sample(35.0, -0.4, 2.0)
    replays = replay_rules(_live(shadow, shadow), config)

    assert replays["sclera"].usable is False
    assert replays["sclera"].reason == NO_USABLE_EYE
    assert replays["sclera"].decided_by is None


def test_a_pool_with_no_eyes_to_judge_is_not_judged(config: EngineConfig) -> None:
    # What a profile measured before the samples were kept looks like: two
    # scleras rejected, and no numbers left to say by how much. Calling the pool
    # "rejected" would be a verdict about two eyes this never looked at, and
    # would count as agreement with the stored one for the wrong reason.
    replays = replay_rules(_live(), config)

    assert replays["sclera_left"].replayed is False
    assert replays["sclera"].replayed is False
    assert replays["sclera"].usable is None


def test_an_eye_with_no_iris_is_held_to_the_strict_tolerance(config: EngineConfig) -> None:
    # The loosened skin tolerance is paid for by the iris test, so an eye
    # without one gets the constant - the replay has to make the same
    # distinction the engine does or it is answering a different question.
    samples = _live(_sample(70.0, -0.5, 3.0))
    without_iris = samples.model_copy(update={"iris_parts": EyeParts(left=None, right=None)})

    with_iris = replay_rules(samples, config)["sclera_left"]
    blind = replay_rules(without_iris, config)["sclera_left"]

    assert with_iris.usable is True
    assert blind.usable is False
    assert blind.reason == "darker_than_skin"


def test_the_replay_agrees_with_the_engine_on_a_real_run(config: EngineConfig) -> None:
    """The claim the whole thing rests on, checked against the engine itself.

    Measure a face, then re-decide it from nothing but the numbers that came
    out. Every zone the engine settled on a replayable reason - or on no reason
    at all - has to come back the same. Zones it settled on pixels are skipped,
    because the replay has no opinion about those and says so.
    """
    detection = _synthetic_detection()
    zones = build_zones(detection, config)
    measured = sample_zones(_face(detection), zones, config)
    statuses = zone_statuses(zones, measured)

    replays = replay_rules(measured.samples, config)

    compared = 0
    for zone, status in statuses.items():
        if status.reason in PIXEL_REASONS or not replays[zone].replayed:
            continue
        compared += 1
        assert replays[zone].usable == status.usable, zone
        if not status.usable:
            assert replays[zone].reason == status.reason, zone

    # Including the eye the synthetic face paints a colour no sclera is, which
    # is the only rejection on it.
    assert compared >= 8
    assert statuses["sclera_right"].reason == "more_chromatic_than_skin"
    assert replays["sclera_right"].reason == "more_chromatic_than_skin"


def test_every_replayable_reason_is_one_the_engine_can_produce() -> None:
    # The two vocabularies must be the same one. A reason invented here would
    # never match a stored verdict, and the comparison would quietly be against
    # nothing.
    engine_reasons = {reason.value for reason in ScleraReason} | {"overexposed"}

    assert engine_reasons >= REPLAYABLE_REASONS
    assert engine_reasons >= PIXEL_REASONS
    assert not (REPLAYABLE_REASONS & PIXEL_REASONS)


def test_nothing_is_both_replayable_and_blind(config: EngineConfig) -> None:
    # `overexposed` is in REPLAYABLE_REASONS and also has a blind half. That is
    # not a contradiction, but it IS the thing a reader will get wrong, so it
    # is written down: the reason is replayable, one of its two signals is not.
    assert "overexposed" in REPLAYABLE_REASONS
    assert CLIPPING_BLIND_SPOT in replay_rules(_live(), config)["right_cheek"].blind_spots
