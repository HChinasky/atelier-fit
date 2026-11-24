"""The three axes, and what happens to them when a zone is missing.

These tests say nothing about whether the axes are RIGHT. They cannot: the
anchor points in ``data/classifier.json`` were reasoned from where skin, hair
and eyes sit in CIELAB, not measured against faces a stylist labelled, and no
such set exists yet. What is checked here is that the arithmetic does what the
data file says - the direction of each axis, the redistribution of weight when a
feature drops out, the reason recorded for every exclusion, and the ceiling on
how far the quiz may move anything.
"""

from __future__ import annotations

import math

import pytest

from color_engine.classifier_data import interpolate, load_classifier_data
from color_engine.config import EngineConfig
from color_engine.features import (
    DYED_HAIR,
    HAIR_NOT_MEASURED,
    IRIS_NOT_MEASURED,
    SCLERA_NOT_MEASURED,
    SKIN_TOO_NEUTRAL,
    Features,
    compute_features,
)
from color_engine.models import (
    Clarity,
    LabSample,
    Metal,
    PreviousHair,
    Quiz,
    Samples,
    Tanning,
    Veins,
)

RULES = load_classifier_data()


def _sample(lightness: float, a: float, b: float, *, pixels: int = 5000) -> LabSample:
    return LabSample(
        L=lightness,
        a=a,
        b=b,
        C=math.hypot(a, b),
        h=math.degrees(math.atan2(b, a)) % 360.0,
        pixels=pixels,
        L_iqr=2.0,
    )


def _samples(
    *,
    skin: LabSample | None = None,
    hair: LabSample | None = None,
    iris: LabSample | None = None,
    sclera: LabSample | None = None,
    without: tuple[str, ...] = (),
) -> Samples:
    """A plausible mid-range face, with any zone overridden or dropped.

    Dropping is a separate argument from overriding on purpose: ``hair=None``
    would be indistinguishable from "leave hair alone", and half of what these
    tests check is what happens when a zone is absent.
    """
    samples = Samples(
        skin=skin if skin is not None else _sample(65.0, 13.0, 16.0),
        hair=hair if hair is not None else _sample(30.0, 4.0, 8.0),
        iris=iris if iris is not None else _sample(40.0, 2.0, 4.0),
        sclera=sclera if sclera is not None else _sample(78.0, 0.5, 2.0),
    )
    for zone in without:
        setattr(samples, zone, None)

    return samples


def _warm_skin() -> LabSample:
    """Golden: b* well ahead of a*, so the hue angle is high."""
    return _sample(65.0, 11.0, 24.0)


def _cool_skin() -> LabSample:
    """Rosy: a* ahead of b*, so the hue angle is low."""
    return _sample(65.0, 18.0, 10.0)


def _features(samples: Samples, quiz: Quiz | None, config: EngineConfig) -> Features:
    return compute_features(samples, quiz, config)


def _reasons(features: Features, axis: str) -> dict[str, str]:
    return {item.feature: item.reason for item in features.exclusions if item.axis == axis}


# --- the anchor mapping itself ----------------------------------------------


def test_an_anchor_maps_to_exactly_its_own_value() -> None:
    anchors = RULES.axes["temperature"].features["skin_hue"].anchors

    assert [interpolate(anchors, x) for x, _ in anchors] == [-1.0, 0.0, 1.0]


def test_between_two_anchors_is_the_straight_line_between_them() -> None:
    anchors = [(0.0, -1.0), (10.0, 1.0)]

    assert interpolate(anchors, 5.0) == pytest.approx(0.0)
    assert interpolate(anchors, 2.5) == pytest.approx(-0.5)


def test_past_the_last_anchor_the_feature_stops_talking() -> None:
    # Clamped, not extrapolated: hair at L* 8 is not more than as deep as hair
    # at L* 20, and an unclamped feature would drown the others in the mean.
    anchors = RULES.axes["depth"].features["hair_L"].anchors

    assert interpolate(anchors, 8.0) == 1.0
    assert interpolate(anchors, 99.0) == -1.0


def test_anchors_may_run_either_way_along_x() -> None:
    # hair_L descends - light hair is a light season - and is not a special case.
    descending = [(70.0, -1.0), (45.0, 0.0), (20.0, 1.0)]
    ascending = [(20.0, 1.0), (45.0, 0.0), (70.0, -1.0)]

    assert interpolate(descending, 57.5) == pytest.approx(-0.5)
    assert interpolate(ascending, 57.5) == pytest.approx(-0.5)


# --- direction of each axis --------------------------------------------------


def test_golden_skin_reads_warm_and_rosy_skin_reads_cool(config: EngineConfig) -> None:
    warm = _features(_samples(skin=_warm_skin()), None, config)
    cool = _features(_samples(skin=_cool_skin()), None, config)

    assert warm.axes.temperature is not None and warm.axes.temperature > 0
    assert cool.axes.temperature is not None and cool.axes.temperature < 0


def test_dark_hair_reads_deep_and_pale_hair_reads_light(config: EngineConfig) -> None:
    deep = _features(_samples(hair=_sample(14.0, 2.0, 5.0)), None, config)
    light = _features(_samples(hair=_sample(78.0, 3.0, 14.0)), None, config)

    assert deep.axes.depth is not None and deep.axes.depth > 0
    assert light.axes.depth is not None and light.axes.depth < 0


def test_a_face_whose_colours_sit_close_together_reads_soft(config: EngineConfig) -> None:
    # Everything within a few L* of everything else, and nothing saturated.
    muted = _samples(
        skin=_sample(62.0, 8.0, 12.0),
        hair=_sample(54.0, 4.0, 7.0),
        iris=_sample(58.0, 1.0, 2.0),
        sclera=_sample(68.0, 0.5, 1.5),
    )

    features = _features(muted, None, config)

    assert features.axes.chroma is not None and features.axes.chroma < 0
    assert features.contrast.value is not None and features.contrast.value < 0.4


def test_contrast_reports_the_raw_distances_beside_the_normalised_one(
    config: EngineConfig,
) -> None:
    samples = _samples(
        skin=_sample(65.0, 13.0, 16.0),
        hair=_sample(20.0, 4.0, 8.0),
        iris=_sample(35.0, 2.0, 4.0),
        sclera=_sample(80.0, 0.5, 2.0),
    )

    contrast = _features(samples, None, config).contrast

    assert contrast.hair_skin == pytest.approx(45.0)
    assert contrast.iris_sclera == pytest.approx(45.0)
    assert contrast.eye_skin == pytest.approx(30.0)
    assert contrast.value is not None and 0.0 <= contrast.value <= 1.0


def test_every_axis_stays_inside_minus_one_to_one(config: EngineConfig) -> None:
    extreme = _samples(
        skin=_sample(20.0, 30.0, 40.0),
        hair=_sample(2.0, 0.0, 0.0),
        iris=_sample(5.0, 25.0, 25.0),
        sclera=_sample(99.0, 0.0, 0.0),
    )

    axes = _features(extreme, None, config).axes

    for value in (axes.temperature, axes.depth, axes.chroma):
        assert value is not None and -1.0 <= value <= 1.0


# --- what happens when a zone is missing -------------------------------------


def test_dyed_hair_leaves_hair_out_of_every_axis_it_touches(config: EngineConfig) -> None:
    features = _features(_samples(), Quiz(natural_hair=False), config)

    assert _reasons(features, "temperature") == {"hair_b": DYED_HAIR}
    assert _reasons(features, "depth") == {"hair_L": DYED_HAIR}
    assert _reasons(features, "contrast") == {"hair_skin": DYED_HAIR}
    assert features.contrast.hair_skin is None


def test_dyed_hair_costs_confidence(config: EngineConfig) -> None:
    natural = _features(_samples(), Quiz(natural_hair=True), config)
    dyed = _features(_samples(), Quiz(natural_hair=False), config)

    assert natural.confidence_factor == 1.0
    assert dyed.confidence_factor == pytest.approx(RULES.missing_data.dyed_hair_confidence)


def test_dropping_hair_hands_its_weight_to_what_is_left(config: EngineConfig) -> None:
    # Not "hair counted as zero" - that would drag depth towards the middle.
    # The surviving features keep the proportion they already stood in.
    samples = _samples()
    depth = RULES.axes["depth"].features
    expected = (
        depth["skin_L"].weight * interpolate(depth["skin_L"].anchors, 65.0)
        + depth["iris_L"].weight * interpolate(depth["iris_L"].anchors, 40.0)
    ) / (depth["skin_L"].weight + depth["iris_L"].weight)

    features = _features(samples, Quiz(natural_hair=False), config)

    assert features.axes.depth == pytest.approx(round(expected, 4))


def test_no_sclera_costs_the_iris_contrast_and_nothing_else(config: EngineConfig) -> None:
    features = _features(_samples(without=("sclera",)), None, config)

    assert _reasons(features, "contrast") == {"iris_sclera": SCLERA_NOT_MEASURED}
    assert features.contrast.iris_sclera is None
    assert features.contrast.value is not None  # hair against skin still stands
    assert features.axes.temperature is not None


def test_no_iris_leaves_it_out_of_all_three_axes(config: EngineConfig) -> None:
    features = _features(_samples(without=("iris",)), None, config)

    assert _reasons(features, "temperature") == {"iris_b": IRIS_NOT_MEASURED}
    assert _reasons(features, "depth") == {"iris_L": IRIS_NOT_MEASURED}
    assert _reasons(features, "chroma") == {"iris_C": IRIS_NOT_MEASURED}
    assert _reasons(features, "contrast") == {"iris_sclera": IRIS_NOT_MEASURED}


def test_unmeasured_hair_is_excluded_for_a_different_reason_than_dye(
    config: EngineConfig,
) -> None:
    # Both drop hair; only one of them is a fact about the person.
    features = _features(_samples(without=("hair",)), None, config)

    assert _reasons(features, "depth") == {"hair_L": HAIR_NOT_MEASURED}
    assert features.confidence_factor == pytest.approx(RULES.missing_data.no_hair_confidence)


def test_a_missing_zone_is_charged_once_not_once_per_axis(config: EngineConfig) -> None:
    # The iris appears in three axes and in the contrast. Charging per mention
    # would punish the shape of the data file rather than the photograph.
    features = _features(_samples(without=("iris",)), None, config)

    assert features.confidence_factor == pytest.approx(RULES.missing_data.no_iris_confidence)


def test_near_neutral_skin_drops_the_hue_feature(config: EngineConfig) -> None:
    # The hue angle of a grey sample is the direction of its noise.
    features = _features(_samples(skin=_sample(65.0, 0.4, 0.5)), None, config)

    assert _reasons(features, "temperature") == {"skin_hue": SKIN_TOO_NEUTRAL}
    assert features.axes.temperature is not None  # skin_b still carries it


def test_an_axis_with_nothing_left_is_none_rather_than_zero(config: EngineConfig) -> None:
    # Zero is a claim - "perfectly neutral". None is the truth: we do not know.
    bare = Samples(skin=None, hair=None, iris=None, sclera=None)

    features = _features(bare, None, config)

    assert features.axes.temperature is None
    assert features.axes.depth is None
    assert features.contrast.value is None


# --- the quiz ----------------------------------------------------------------


def test_the_quiz_cannot_turn_a_cool_face_warm(config: EngineConfig) -> None:
    # Every answer pointing warm, on skin the photo says is clearly cool.
    all_warm = Quiz(metal=Metal.GOLD, veins=Veins.GREEN, tanning=Tanning.TANS)

    photo = _features(_samples(skin=_cool_skin()), None, config)
    nudged = _features(_samples(skin=_cool_skin()), all_warm, config)

    assert photo.axes.temperature is not None and photo.axes.temperature < 0
    assert nudged.axes.temperature is not None
    assert nudged.axes.temperature <= 0.0


def test_the_quiz_cannot_turn_a_warm_face_cool(config: EngineConfig) -> None:
    all_cool = Quiz(metal=Metal.SILVER, veins=Veins.BLUE, tanning=Tanning.BURNS)

    nudged = _features(_samples(skin=_warm_skin()), all_cool, config)

    assert nudged.axes.temperature is not None and nudged.axes.temperature >= 0.0


def test_the_quiz_moves_the_axis_but_no_further_than_the_cap(config: EngineConfig) -> None:
    quiz = Quiz(metal=Metal.GOLD, veins=Veins.GREEN, tanning=Tanning.TANS)
    cap = RULES.axes["temperature"].quiz is not None

    plain = _features(_samples(skin=_warm_skin()), None, config)
    nudged = _features(_samples(skin=_warm_skin()), quiz, config)

    assert cap
    assert nudged.axes.temperature is not None and plain.axes.temperature is not None
    assert nudged.axes.temperature > plain.axes.temperature
    assert abs(nudged.quiz_shift) <= RULES.axes["temperature"].quiz.max_shift + 1e-9  # type: ignore[union-attr]


def test_a_quiz_nobody_answered_changes_nothing(config: EngineConfig) -> None:
    plain = _features(_samples(), None, config)
    empty = _features(_samples(), Quiz(), config)

    assert empty.axes.temperature == plain.axes.temperature
    assert empty.quiz_shift == 0.0


def test_contradictory_answers_are_settled_by_the_weight_of_the_question(
    config: EngineConfig,
) -> None:
    # The stylist rates tanning 4, veins 3 and gold-or-silver 2 for telling
    # undertone, so opposing answers do not cancel: the better question wins.
    weights = RULES.axes["temperature"].quiz.question_weights  # type: ignore[union-attr]
    assert weights["tanning"] > weights["veins"] > weights["metal"]

    plain = _features(_samples(), None, config)
    veins_wins = _features(_samples(), Quiz(metal=Metal.GOLD, veins=Veins.BLUE), config)
    tanning_wins = _features(_samples(), Quiz(veins=Veins.BLUE, tanning=Tanning.TANS), config)

    assert veins_wins.quiz_shift < 0.0  # blue veins (w3) outvote gold (w2)
    assert tanning_wins.quiz_shift > 0.0  # tanning (w4) outvotes blue veins (w3)
    assert plain.quiz_shift == 0.0


def test_a_both_answer_is_a_vote_for_neutral_and_dilutes_the_rest(config: EngineConfig) -> None:
    only = _features(_samples(), Quiz(tanning=Tanning.TANS), config)
    diluted = _features(_samples(), Quiz(tanning=Tanning.TANS, metal=Metal.BOTH), config)

    assert 0.0 < diluted.quiz_shift < only.quiz_shift


def test_clarity_nudges_chroma_within_the_cap_and_leaves_temperature_alone(
    config: EngineConfig,
) -> None:
    plain = _features(_samples(), None, config)
    clear = _features(_samples(), Quiz(clarity=Clarity.CLEAR), config)
    soft = _features(_samples(), Quiz(clarity=Clarity.SOFT), config)
    cap = RULES.axes["chroma"].quiz.max_shift  # type: ignore[union-attr]

    assert plain.axes.chroma is not None and clear.axes.chroma is not None
    assert soft.axes.chroma is not None
    assert clear.axes.chroma >= plain.axes.chroma >= soft.axes.chroma
    assert clear.axes.chroma != soft.axes.chroma
    assert abs(clear.chroma_quiz_shift) <= cap + 1e-9
    assert abs(soft.chroma_quiz_shift) <= cap + 1e-9
    assert clear.axes.temperature == plain.axes.temperature
    assert clear.quiz_shift == 0.0


def test_clarity_never_carries_chroma_across_zero(config: EngineConfig) -> None:
    plain = _features(_samples(), None, config)
    assert plain.axes.chroma is not None and plain.axes.chroma != 0.0

    # Answer against the photograph: the nudge may bring it to zero, never past.
    against = Clarity.SOFT if plain.axes.chroma > 0 else Clarity.CLEAR
    nudged = _features(_samples(), Quiz(clarity=against), config)

    assert nudged.axes.chroma is not None
    assert (nudged.axes.chroma >= 0.0) if plain.axes.chroma > 0 else (nudged.axes.chroma <= 0.0)


def test_an_unsure_clarity_changes_nothing(config: EngineConfig) -> None:
    plain = _features(_samples(), None, config)
    unsure = _features(_samples(), Quiz(clarity=Clarity.UNSURE), config)

    assert unsure.axes.chroma == plain.axes.chroma
    assert unsure.chroma_quiz_shift == pytest.approx(0.0)


def test_the_engine_accepts_clarity_in_a_quiz_from_json() -> None:
    """pose-service parses the quiz with Quiz.model_validate_json - the field
    must be accepted there, not just constructed in Python."""
    parsed = Quiz.model_validate_json('{"natural_hair": true, "metal": "silver", "clarity": "soft"}')

    assert parsed.clarity is Clarity.SOFT


# --- a zone earns its weight with its own chroma ---------------------------


def _share(features: Features, axis: str, feature: str, samples: Samples) -> float:
    """What fraction of an axis one feature actually carried."""
    spec = RULES.axes[axis]
    weights = {}
    for name, item in spec.features.items():
        zone = name.partition("_")[0]
        sample = {"skin": samples.skin, "hair": samples.hair, "iris": samples.iris}.get(zone)
        reliability = (
            1.0
            if spec.zone_reliability is None or sample is None
            else interpolate(spec.zone_reliability.anchors, sample.C)
        )
        weights[name] = item.weight * reliability
    del features

    return weights[feature] / sum(weights.values())


def test_saturated_hair_counts_for_more_when_the_skin_is_washed_out(
    config: EngineConfig,
) -> None:
    # The first classified frame: skin at C* 9.9 holding 0.45 of the temperature
    # axis while natural red hair at C* 23.8 held 0.15.
    washed = _samples(
        skin=_sample(86.9, 7.5, 6.4),  # C* 9.9
        hair=_sample(23.8, 5.9, 23.1),  # C* 23.8
    )
    ordinary = _samples(
        skin=_sample(66.0, 13.0, 18.0),  # C* 22.2
        hair=_sample(23.8, 5.9, 23.1),
    )

    washed_share = _share(_features(washed, None, config), "temperature", "hair_b", washed)
    ordinary_share = _share(_features(ordinary, None, config), "temperature", "hair_b", ordinary)

    assert washed_share > ordinary_share


def test_the_skin_hue_counts_for_less_when_the_skin_is_washed_out(
    config: EngineConfig,
) -> None:
    washed = _samples(skin=_sample(86.9, 7.5, 6.4))
    ordinary = _samples(skin=_sample(66.0, 13.0, 18.0))

    assert _share(_features(washed, None, config), "temperature", "skin_hue", washed) < _share(
        _features(ordinary, None, config), "temperature", "skin_hue", ordinary
    )


def test_at_ordinary_chroma_the_weights_are_the_declared_ones(config: EngineConfig) -> None:
    # The curve tops out, so a face with colour in every zone is scored exactly
    # as it was before this existed.
    strong = _samples(
        skin=_sample(66.0, 13.0, 18.0),
        hair=_sample(30.0, 8.0, 20.0),
        iris=_sample(40.0, 10.0, 18.0),
    )
    features = _features(strong, None, config)
    declared = RULES.axes["temperature"].features

    for name in declared:
        assert _share(features, "temperature", name, strong) == pytest.approx(
            declared[name].weight, abs=1e-9
        )


def test_the_rule_is_about_chroma_and_not_about_hair(config: EngineConfig) -> None:
    # The same arithmetic demotes washed-out HAIR on a face whose skin has
    # plenty of colour. Nothing in here knows what a redhead is.
    flat_hair = _samples(
        skin=_sample(66.0, 13.0, 18.0),
        hair=_sample(20.0, 1.0, 2.0),  # C* 2.2, near-neutral dark hair
    )
    strong_hair = _samples(
        skin=_sample(66.0, 13.0, 18.0),
        hair=_sample(20.0, 8.0, 20.0),
    )

    assert _share(_features(flat_hair, None, config), "temperature", "hair_b", flat_hair) < _share(
        _features(strong_hair, None, config), "temperature", "hair_b", strong_hair
    )


def test_a_near_neutral_zone_keeps_a_floor_rather_than_vanishing(
    config: EngineConfig,
) -> None:
    # Zero would make the axis jump as C* crossed a threshold, and a near-neutral
    # zone still says something with the sign of its b*.
    anchors = RULES.axes["temperature"].zone_reliability
    assert anchors is not None
    assert interpolate(anchors.anchors, 0.0) > 0.0


def test_dyed_hair_is_still_excluded_outright_not_merely_down_weighted(
    config: EngineConfig,
) -> None:
    # Reliability is about how much colour a zone has. Dye is about whether the
    # colour is the person's, which no amount of chroma answers.
    features = _features(_samples(hair=_sample(23.8, 5.9, 23.1)), Quiz(natural_hair=False), config)

    assert _reasons(features, "temperature") == {"hair_b": DYED_HAIR}


def test_the_previous_hair_colour_is_a_note_for_the_stylist_and_changes_nothing(
    config: EngineConfig,
) -> None:
    dyed = _features(_samples(), Quiz(natural_hair=False), config)
    noted = _features(
        _samples(), Quiz(natural_hair=False, previous_hair=PreviousHair.DARK), config
    )

    assert noted.previous_hair == "dark"
    assert noted.as_debug()["previous_hair"] == "dark"
    # Not a single number moves: the dyed hair stays out, and the note stays a note.
    assert noted.axes == dyed.axes
    assert noted.confidence_factor == dyed.confidence_factor
    assert noted.exclusions == dyed.exclusions


def test_the_previous_hair_colour_is_ignored_when_the_hair_is_not_dyed(
    config: EngineConfig,
) -> None:
    for quiz in (Quiz(natural_hair=True, previous_hair=PreviousHair.RED), Quiz(previous_hair=PreviousHair.RED)):
        assert _features(_samples(), quiz, config).previous_hair is None
