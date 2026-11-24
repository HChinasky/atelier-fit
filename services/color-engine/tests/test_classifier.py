"""Scoring the 12 seasons.

A warning that belongs on every one of these: **nothing here claims the
classifier is correct about real faces.** It has never been run against a set of
photographs a stylist labelled, because no such set exists. Every prototype and
every weight in ``data/classifier.json`` was reasoned from the structure of the
12-season system, and until somebody measures them these tests check internal
consistency and nothing more - that a face sitting on a prototype comes back as
that season, that a face between two comes back as a tie, that the confidence is
the product it claims to be, and that the data cannot change without the version
changing with it.
"""

from __future__ import annotations

import json
from pathlib import Path

import pytest

from color_engine.classifier_data import (
    CLASSIFIER_DATA_PATH,
    ClassifierData,
    data_hash,
    load_classifier_data,
)
from color_engine.classify import AXES, classify, prototype, weights
from color_engine.config import EngineConfig
from color_engine.features import Exclusion, Features
from color_engine.models import Axes, Contrast, Season

RULES = load_classifier_data()

KNOWN_DATA_HASHES = {
    "draft-1": "af027c6f421b12f19b5f8e5dd1f06284db8ba63ab30132ae3d133b9f7eb042fa",
    "draft-2": "66db95682de3d770450f72e0b76b070219ee41d9eaa84a1dc245e9d85cfb1052",
    "draft-3": "a9120423791d5a69e93572491d0410fd549df0028be73c71b164eb3bfe68930a",
    "draft-4": "eb781f244e3d5c87e9f233c3e5c6a69ad8ef3dd028888abe4f4c2c0e540a5085",
}
"""Version to SHA-256 of ``data/classifier.json``.

This mapping is the whole mechanism behind "changing the classifier means
changing the version". Edit a weight and the hash moves; the test below fails
until somebody adds a new version and a new entry. Deliberately tedious - it is
tedious in exactly the place where a quiet edit would change what everybody's
season is while the contract still said ``draft-1``.
"""


def _at(temperature: float | None, depth: float | None, chroma: float | None) -> Features:
    return Features(
        axes=Axes(temperature=temperature, depth=depth, chroma=chroma),
        contrast=Contrast(),
    )


def _on(slug: str) -> Features:
    point = prototype(slug, RULES)
    return _at(point["temperature"], point["depth"], point["chroma"])


def _between(one: str, other: str) -> Features:
    first, second = prototype(one, RULES), prototype(other, RULES)
    middle = {axis: (first[axis] + second[axis]) / 2 for axis in AXES}
    return _at(middle["temperature"], middle["depth"], middle["chroma"])


# --- the data file -----------------------------------------------------------


def test_the_data_file_cannot_change_without_the_version_changing() -> None:
    # If this fails, you changed data/classifier.json. That is allowed - but
    # bump `version` in the file and add its hash to KNOWN_DATA_HASHES, because
    # a result stamped draft-1 has to mean one particular set of numbers.
    assert RULES.version in KNOWN_DATA_HASHES, (
        f"classifier version {RULES.version!r} has no recorded hash"
    )
    assert data_hash() == KNOWN_DATA_HASHES[RULES.version], (
        f"data/classifier.json changed while version stayed {RULES.version!r}"
    )


def test_the_hash_is_over_the_bytes_not_the_parsed_object() -> None:
    # Reformatting is a change somebody should have to look at too: a hash that
    # forgives whitespace forgives a stray minus sign in a reflowed line.
    assert len(data_hash()) == 64
    assert data_hash() != json.dumps(json.loads(CLASSIFIER_DATA_PATH.read_text(encoding="utf-8")))


def test_the_hash_survives_a_windows_checkout(tmp_path: Path) -> None:
    # This repo stores LF and checks out through core.autocrlf, so the same
    # committed file is CRLF here and LF in CI. Line endings are the one thing
    # the hash has to forgive, or the version gate fails on whichever platform
    # it was not recorded on - which is a fact about the checkout, not an edit.
    raw = CLASSIFIER_DATA_PATH.read_bytes().replace(b"\r\n", b"\n")
    unix, windows = tmp_path / "lf.json", tmp_path / "crlf.json"
    unix.write_bytes(raw)
    windows.write_bytes(raw.replace(b"\n", b"\r\n"))

    assert data_hash(unix) == data_hash(windows) == data_hash()


def test_the_file_names_all_twelve_seasons_and_no_others() -> None:
    assert {Season(slug) for slug in RULES.seasons.prototypes} == set(Season)


def test_every_prototype_names_two_different_axes() -> None:
    for slug, spec in RULES.seasons.prototypes.items():
        assert spec.leading[0] in AXES, slug
        assert spec.secondary[0] in AXES, slug
        assert spec.leading[0] != spec.secondary[0], slug


def test_prose_in_the_data_file_does_not_break_loading() -> None:
    # Every number in there carries a `why`. Unknown keys are ignored so the
    # reasoning can be written down without a code change.
    raw = json.loads(CLASSIFIER_DATA_PATH.read_text(encoding="utf-8"))
    raw["a_key_nobody_declared"] = "prose"
    raw["seasons"]["another_one"] = 42

    assert ClassifierData.model_validate(raw).version == RULES.version


# --- scoring -----------------------------------------------------------------


@pytest.mark.parametrize("slug", sorted(RULES.seasons.prototypes))
def test_a_face_sitting_on_a_prototype_is_that_season(slug: str, config: EngineConfig) -> None:
    result = classify(_on(slug), None, config)

    assert result.seasons[0].slug is Season(slug)
    assert result.seasons[0].score > result.seasons[1].score


@pytest.mark.parametrize("slug", sorted(RULES.seasons.prototypes))
def test_a_prototype_is_confident_about_itself(slug: str, config: EngineConfig) -> None:
    result = classify(_on(slug), None, config)

    assert result.info is not None and not result.info.low_confidence


@pytest.mark.parametrize(
    ("one", "other"),
    [
        ("light_spring", "light_summer"),
        ("deep_autumn", "deep_winter"),
        ("true_spring", "bright_spring"),
        ("soft_summer", "true_summer"),
    ],
)
def test_a_face_between_two_seasons_gets_both_and_little_confidence(
    one: str, other: str, config: EngineConfig
) -> None:
    result = classify(_between(one, other), None, config)

    assert {season.slug.value for season in result.seasons} == {one, other}
    assert result.debug["margin"] == pytest.approx(0.0, abs=1e-9)
    assert result.info is not None and result.info.low_confidence


def test_exactly_two_seasons_come_back(config: EngineConfig) -> None:
    result = classify(_on("true_winter"), None, config)

    assert len(result.seasons) == 2


def test_all_twelve_scores_are_a_distribution(config: EngineConfig) -> None:
    result = classify(_on("true_winter"), None, config)

    scores = result.debug["scores"]
    assert len(scores) == len(Season)
    assert sum(scores.values()) == pytest.approx(1.0, abs=1e-3)
    assert all(0.0 <= score <= 1.0 for score in scores.values())


def test_the_same_face_always_scores_the_same(config: EngineConfig) -> None:
    first = classify(_at(0.3, -0.2, 0.55), None, config)
    second = classify(_at(0.3, -0.2, 0.55), None, config)

    assert first.seasons == second.seasons
    assert first.confidence == second.confidence


def test_the_leading_axis_counts_for_most(config: EngineConfig) -> None:
    per_axis = weights("deep_winter", RULES)

    assert per_axis["depth"] > per_axis["temperature"] > per_axis["chroma"]
    assert per_axis["chroma"] > 0, "a zero third axis would let two prototypes tie exactly"


def test_a_face_with_no_axes_at_all_gets_no_season(config: EngineConfig) -> None:
    result = classify(_at(None, None, None), None, config)

    assert result.seasons == []
    assert result.confidence is None
    assert result.info is None


def test_a_missing_axis_is_skipped_rather_than_guessed(config: EngineConfig) -> None:
    # A cool, bright face whose depth could not be measured. Two axes are still
    # enough to rank it.
    result = classify(_at(-0.9, None, 0.8), None, config)

    assert {season.slug.value for season in result.seasons} == {"true_winter", "bright_winter"}

    # And the pairs that differ ONLY in the missing axis come out exactly level
    # rather than one of them being favoured by a depth nobody measured.
    scores = result.debug["scores"]
    assert scores["light_summer"] == scores["deep_winter"]
    assert scores["light_spring"] == scores["deep_autumn"]


# --- confidence --------------------------------------------------------------


def test_the_classifier_stamps_the_data_version_and_the_draft_flag(
    config: EngineConfig,
) -> None:
    result = classify(_on("true_autumn"), None, config)

    assert result.info is not None
    assert result.info.version == RULES.version
    assert result.info.draft is RULES.draft


def test_a_bad_photograph_multiplies_the_confidence_down(config: EngineConfig) -> None:
    clean = classify(_on("true_autumn"), None, config, quality_factor=1.0)
    murky = classify(_on("true_autumn"), None, config, quality_factor=0.5)

    assert clean.confidence is not None and murky.confidence is not None
    assert murky.confidence == pytest.approx(clean.confidence * 0.5, abs=1e-3)


def test_missing_zones_multiply_the_confidence_down_too(config: EngineConfig) -> None:
    whole = classify(_on("true_autumn"), None, config)
    partial = classify(
        Features(axes=_on("true_autumn").axes, contrast=Contrast(), confidence_factor=0.75),
        None,
        config,
    )

    assert whole.confidence is not None and partial.confidence is not None
    assert partial.confidence == pytest.approx(whole.confidence * 0.75, abs=1e-3)


def test_the_confidence_debug_shows_the_whole_multiplication(config: EngineConfig) -> None:
    # A confidence of 0.31 should be arguable, which means every factor that
    # made it 0.31 has to be visible in one place.
    features = Features(axes=_on("soft_autumn").axes, contrast=Contrast(), confidence_factor=0.8)

    result = classify(features, None, config, quality_factor=0.7)

    shown = result.debug["confidence"]
    assert shown["features_factor"] == pytest.approx(0.8)
    assert shown["quality_factor"] == pytest.approx(0.7)
    assert shown["final"] == pytest.approx(shown["from_margin"] * 0.8 * 0.7, abs=1e-3)
    assert shown["final"] == result.confidence


def test_a_season_is_still_returned_below_the_threshold(config: EngineConfig) -> None:
    # Low confidence is a label on the answer, not a refusal to give one.
    result = classify(_on("true_winter"), None, config, quality_factor=0.05)

    assert result.seasons
    assert result.info is not None and result.info.low_confidence
    assert result.confidence is not None
    assert result.confidence < RULES.confidence.min_confidence_for_season


def test_the_contract_carries_the_exclusions_and_not_only_the_debug(
    config: EngineConfig,
) -> None:
    # A confidence of 0.31 with no explanation is an assertion. The page that
    # has to justify it to a stylist reads the contract, not our debug objects.
    features = Features(
        axes=_on("soft_autumn").axes,
        contrast=Contrast(),
        exclusions=[Exclusion("depth", "hair_L", "dyed_hair")],
        confidence_factor=0.75,
    )

    info = classify(features, None, config, quality_factor=0.8).info

    assert info is not None
    assert [(item.axis, item.feature, item.reason) for item in info.exclusions] == [
        ("depth", "hair_L", "dyed_hair")
    ]
    assert info.confidence is not None
    assert info.confidence.features_factor == pytest.approx(0.75)
    assert info.confidence.quality_factor == pytest.approx(0.8)
    assert info.confidence.final == pytest.approx(
        info.confidence.from_margin * 0.75 * 0.8, abs=1e-3
    )


def test_a_classifier_that_had_everything_lists_no_exclusions(config: EngineConfig) -> None:
    info = classify(_on("true_winter"), None, config).info

    assert info is not None and info.exclusions == []


def test_a_dead_heat_reports_a_zero_margin_and_a_non_zero_curve(
    config: EngineConfig,
) -> None:
    # The live frame had deep_winter and true_summer tied at 0.1445, and the
    # page said "how far the first season is ahead of the second: 0.150". Both
    # numbers were real; one of them was under the other's name.
    result = classify(_between("light_spring", "light_summer"), None, config)

    assert result.info is not None and result.info.confidence is not None
    assert result.info.confidence.margin == 0.0
    assert result.info.confidence.from_margin > 0.0


def test_the_raw_margin_grows_with_a_clearer_winner(config: EngineConfig) -> None:
    tied = classify(_between("deep_autumn", "deep_winter"), None, config)
    clear = classify(_on("deep_winter"), None, config)

    assert tied.info is not None and tied.info.confidence is not None
    assert clear.info is not None and clear.info.confidence is not None
    assert clear.info.confidence.margin > tied.info.confidence.margin
