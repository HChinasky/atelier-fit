"""The draft palette generator.

Same warning as the classifier, and louder: **nobody chose these colours.** They
are constructed from the season prototypes by the rules in ``data/palette.json``
so the page has something to render and a stylist has something to disagree
with. These tests check that the construction is deterministic, that everything
it produces is a colour a screen can actually show, and that the "avoid" group
really is the other side of the axis the season is named for. Whether the
colours suit anybody is not a question a test can answer.
"""

from __future__ import annotations

import json
import math
from pathlib import Path
from typing import Any

import colour
import numpy as np
import pytest
from typer.testing import CliRunner

from color_engine.classifier_data import load_classifier_data
from color_engine.cli import app
from color_engine.palettes import (
    AVOID_GROUP,
    PALETTE_DATA_PATH,
    RECOMMENDED_GROUPS,
    PaletteData,
    draft_palettes,
    load_palette_data,
    palette_data_hash,
)

RULES = load_classifier_data()
PALETTE = load_palette_data()

KNOWN_GENERATOR_HASHES = {
    "draft-2": "19a294414bfe88df9efe5b49e53feeaf1714e3f88ee2826bc0227e6a96e91c35",
}
"""Generator version to SHA-256 of ``data/palette.json``, as with the classifier.

The output of this generator is committed and seeded. A rule that changed
without the version changing would reseed everybody's palette while the file
still claimed to be ``draft-1``.
"""


Node = dict[str, Any]


def _document() -> Node:
    return draft_palettes()


def _group(season: Node, name: str) -> Node:
    group: Node = next(item for item in season["groups"] if item["name"] == name)
    return group


def _colors(season: Node, name: str) -> list[Node]:
    colors: list[Node] = _group(season, name)["colors"]
    return colors


def _channels(color: Node) -> list[int]:
    return [int(color["hex"][index : index + 2], 16) for index in (1, 3, 5)]


def _mean_lightness(colors: list[Node]) -> float:
    return sum(float(color["lab"]["L"]) for color in colors) / len(colors)


def _mean_chroma(colors: list[Node]) -> float:
    return sum(math.hypot(c["lab"]["a"], c["lab"]["b"]) for c in colors) / len(colors)


def _hue(color: Node) -> float:
    return math.degrees(math.atan2(color["lab"]["b"], color["lab"]["a"])) % 360.0


def _signed_turn(one: float, other: float) -> float:
    """``one`` minus ``other``, wrapped into -180..180."""
    return (one - other + 180.0) % 360.0 - 180.0


# --- the rules file ----------------------------------------------------------


def test_the_rules_cannot_change_without_the_generator_version_changing() -> None:
    assert PALETTE.generator_version in KNOWN_GENERATOR_HASHES
    assert palette_data_hash() == KNOWN_GENERATOR_HASHES[PALETTE.generator_version]


def test_the_output_says_it_is_a_draft_and_says_why() -> None:
    document = _document()

    assert document["draft"] is True
    assert document["generator_version"] == PALETTE.generator_version
    assert document["classifier_version"] == RULES.version
    assert "stylist" in document["warning"]


def test_prose_in_the_rules_file_does_not_break_loading() -> None:
    raw = json.loads(PALETTE_DATA_PATH.read_text(encoding="utf-8"))
    raw["a_key_nobody_declared"] = "prose"

    assert PaletteData.model_validate(raw).generator_version == PALETTE.generator_version


# --- shape -------------------------------------------------------------------


def test_every_season_gets_every_group() -> None:
    document = _document()

    assert {season["slug"] for season in document["seasons"]} == set(RULES.seasons.prototypes)
    for season in document["seasons"]:
        names = [group["name"] for group in season["groups"]]
        assert names == [*RECOMMENDED_GROUPS, AVOID_GROUP], season["slug"]


@pytest.mark.parametrize("name", [*RECOMMENDED_GROUPS, AVOID_GROUP])
def test_no_group_is_empty_and_none_is_the_wrong_size(name: str) -> None:
    for season in _document()["seasons"]:
        colors = _colors(season, name)
        assert 5 <= len(colors) <= 8, (season["slug"], name)


def test_only_avoid_is_not_recommended() -> None:
    for season in _document()["seasons"]:
        recommended = {group["name"] for group in season["groups"] if group["recommended"]}
        assert recommended == set(RECOMMENDED_GROUPS), season["slug"]


def test_a_group_never_contains_the_same_colour_twice() -> None:
    # It did once: a light season's two lightest colours both clamped to pure
    # white, and a group of six that is really a group of five is a bug.
    for season in _document()["seasons"]:
        for group in season["groups"]:
            hexes = [color["hex"] for color in group["colors"]]
            assert len(set(hexes)) == len(hexes), (season["slug"], group["name"], hexes)


def test_the_seasons_come_out_in_a_stable_order() -> None:
    # `sort` reaches the database, so the order has to survive somebody
    # rewriting the JSON by hand.
    first = [season["slug"] for season in _document()["seasons"]]
    second = [season["slug"] for season in _document()["seasons"]]

    assert first == second
    assert [season["sort"] for season in _document()["seasons"]] == list(range(len(first)))


# --- every colour is a colour ------------------------------------------------


def test_every_colour_is_inside_srgb() -> None:
    # By construction, since out-of-gamut chroma is compressed rather than the
    # colour dropped - but this is the property the whole gamut step exists for.
    for season in _document()["seasons"]:
        for group in season["groups"]:
            for color in group["colors"]:
                assert len(color["hex"]) == 7 and color["hex"][0] == "#"
                assert all(0 <= channel <= 255 for channel in _channels(color))


def test_the_lab_beside_a_colour_is_that_colour() -> None:
    # Stored from the rounded 8-bit value, not from the request: the hex and the
    # numbers next to it on the page have to be the same colour.
    for season in _document()["seasons"]:
        for color in _colors(season, "accents"):
            lab = colour.XYZ_to_Lab(colour.sRGB_to_XYZ(np.array(_channels(color)) / 255.0))
            assert float(lab[0]) == pytest.approx(color["lab"]["L"], abs=0.01)
            assert float(lab[1]) == pytest.approx(color["lab"]["a"], abs=0.01)
            assert float(lab[2]) == pytest.approx(color["lab"]["b"], abs=0.01)


def test_compression_is_recorded_rather_than_hidden() -> None:
    # Some colours ask sRGB for more chroma than it has. That is worth knowing,
    # so it is a field rather than a silent adjustment.
    document = _document()
    flags = [
        color["compressed"]
        for season in document["seasons"]
        for group in season["groups"]
        for color in group["colors"]
    ]

    assert any(flags), "nothing was compressed, which means the flag is not wired up"
    assert not all(flags)


# --- determinism -------------------------------------------------------------


def test_the_same_rules_always_produce_the_same_bytes() -> None:
    # The file is committed and diffed. A generator that drifted by one bit
    # would produce a diff nobody could review.
    first = json.dumps(_document(), sort_keys=True)
    second = json.dumps(_document(), sort_keys=True)

    assert first == second


def test_the_command_writes_the_file_it_says_it_wrote(tmp_path: Path) -> None:
    out = tmp_path / "nested" / "season_palettes.json"

    result = CliRunner().invoke(app, ["palettes", "draft", "--out", str(out)])

    assert result.exit_code == 0, result.output
    assert "draft" in result.output
    assert json.loads(out.read_text(encoding="utf-8")) == _document()


def test_the_written_file_uses_lf_wherever_it_is_generated(tmp_path: Path) -> None:
    # Committed file, generated on Windows and on Linux. Platform line endings
    # would make a regeneration look like 300 changed lines.
    out = tmp_path / "season_palettes.json"

    CliRunner().invoke(app, ["palettes", "draft", "--out", str(out)])

    raw = out.read_bytes()
    assert b"\r\n" not in raw
    assert raw.endswith(b"\n")


# --- avoid is the other side of the leading axis -----------------------------


def test_avoid_flips_the_leading_axis_and_the_temperature_and_nothing_else() -> None:
    for season in _document()["seasons"]:
        leading = RULES.seasons.prototypes[season["slug"]].leading[0]
        recommended = _group(season, "accents")["point"]
        avoid = _group(season, AVOID_GROUP)["point"]
        flipped = {leading, "temperature"}

        for axis in flipped:
            assert avoid[axis] == pytest.approx(-recommended[axis]), (season["slug"], axis)
        for axis, value in recommended.items():
            if axis not in flipped:
                assert avoid[axis] == pytest.approx(value), (season["slug"], axis)


def test_a_depth_led_season_avoids_the_opposite_lightness() -> None:
    centre = PALETTE.lightness.centre

    for season in _document()["seasons"]:
        if RULES.seasons.prototypes[season["slug"]].leading[0] != "depth":
            continue
        accents = _mean_lightness(_colors(season, "accents"))
        avoid = _mean_lightness(_colors(season, AVOID_GROUP))

        assert (accents - centre) * (avoid - centre) < 0, season["slug"]


def test_a_chroma_led_season_avoids_the_opposite_saturation() -> None:
    for season in _document()["seasons"]:
        if RULES.seasons.prototypes[season["slug"]].leading[0] != "chroma":
            continue
        accents = _mean_chroma(_colors(season, "accents"))
        avoid = _mean_chroma(_colors(season, AVOID_GROUP))
        bright = RULES.seasons.prototypes[season["slug"]].leading[1] > 0

        assert (accents > avoid) is bright, season["slug"]


def _in_band(hue: float, low: float, high: float) -> bool:
    """Is this hue inside an arc that may wrap past 360?"""
    span = (hue - low) % 360.0

    return span <= (high - low) % 360.0


def _recommended(season: Node) -> list[Node]:
    return [c for g in season["groups"] if g["recommended"] for c in g["colors"]]


# A guard margin inside each band: the gamut step preserves hue exactly, but
# rounding to 8 bits shifts it by a fraction of a degree.
GUARD = 3.0


def test_no_cool_season_has_a_recommended_golden_yellow() -> None:
    # deep_winter used to carry #A5896B and #A87B45 - ochre and mustard.
    low, high = PALETTE.hues.bands["warm_yellow_orange"]

    for season in _document()["seasons"]:
        if RULES.seasons.prototypes[season["slug"]].secondary[1] > 0:
            continue
        if _temperature(season) > 0:
            continue
        for color in _recommended(season):
            hue = _hue(color)
            assert not _in_band(hue, low + GUARD, high - GUARD), (
                season["slug"],
                color["hex"],
                round(hue, 1),
            )


def test_no_warm_season_has_a_recommended_blue_violet() -> None:
    low, high = PALETTE.hues.bands["cool_blue_violet"]

    for season in _document()["seasons"]:
        if _temperature(season) < 0:
            continue
        for color in _recommended(season):
            hue = _hue(color)
            assert not _in_band(hue, low + GUARD, high - GUARD), (
                season["slug"],
                color["hex"],
                round(hue, 1),
            )


def test_the_avoid_group_draws_from_the_other_temperature() -> None:
    # Temperature now decides which hues a season may have at all, so an avoid
    # group drawn from the same bands would be the right colours at the wrong
    # lightness - which is not what drains a face.
    for season in _document()["seasons"]:
        recommended = _group(season, "accents")["point"]
        avoid = _group(season, AVOID_GROUP)["point"]

        assert avoid["temperature"] == pytest.approx(-recommended["temperature"]), season["slug"]


def test_a_cool_season_avoids_exactly_the_golden_yellows_it_may_not_wear() -> None:
    low, high = PALETTE.hues.bands["warm_yellow_orange"]
    cool = [s for s in _document()["seasons"] if _temperature(s) < 0]
    assert cool

    hit = 0
    for season in cool:
        for color in _colors(season, AVOID_GROUP):
            if _in_band(_hue(color), low, high):
                hit += 1

    assert hit, "no cool season's avoid group reaches the warm band it excludes"


def _temperature(season: Node) -> float:
    value: float = season["point"]["temperature"]
    return value


def test_a_season_named_for_its_temperature_gets_the_stricter_bands() -> None:
    # Where temperature is only secondary the season is named for its depth or
    # its clarity, and the warm/cool call is a modifier rather than the point.
    warm = PALETTE.hues.allowed["warm"]
    leading_span = sum(high - low for low, high in warm.leading)
    secondary_span = sum(high - low for low, high in warm.secondary)

    assert secondary_span > leading_span


def test_a_deep_cool_season_has_no_olive() -> None:
    # A hue band is not cool or warm on its own. Lemon and yellow-green read
    # cool while they are light; at the L* a deep season sits at they are olive,
    # which is warm. deep_winter's first accent used to be #748745.
    for season in _document()["seasons"]:
        point = season["point"]
        if point["temperature"] > 0 or point["depth"] <= 0:
            continue
        for color in _recommended(season):
            assert not _in_band(_hue(color), 95.0, 135.0), (season["slug"], color["hex"])


def test_a_deep_warm_season_may_still_have_its_moss() -> None:
    # The rule is about the cool stance only. Olive and moss are what a deep
    # autumn is for, and a rule that took them away would be fixing the wrong
    # thing.
    deep_warm = [
        s
        for s in _document()["seasons"]
        if s["point"]["temperature"] > 0 and s["point"]["depth"] > 0
    ]
    assert deep_warm

    hues = [_hue(c) for s in deep_warm for c in _recommended(s)]
    assert any(95.0 <= hue <= 160.0 for hue in hues)
