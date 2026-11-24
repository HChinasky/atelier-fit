"""Light falling from one side.

The measure is stops - the base-2 ratio of linear luminance - and not a
difference in L*. That choice is the substance of these tests: the same lighting
ratio has to give the same verdict on light and on deep skin, because a
threshold in L* would reject the first for lighting the second gets away with.
"""

from __future__ import annotations

import pytest

from color_engine.config import EngineConfig
from color_engine.models import LabSample, RejectReason, Samples, SkinParts
from color_engine.quality import relative_luminance, stops_between, uneven_light
from color_engine.sampling import Measurements


def _sample(lightness: float, a: float = 12.0, b: float = 15.0) -> LabSample:
    return LabSample(L=lightness, a=a, b=b, C=19.2, h=51.0, pixels=900, L_iqr=4.0)


def _face(left: float | None, right: float | None, forehead: float | None) -> Measurements:
    parts = SkinParts(
        left_cheek=None if left is None else _sample(left),
        right_cheek=None if right is None else _sample(right),
        forehead=None if forehead is None else _sample(forehead),
    )
    pooled = [p for p in (parts.left_cheek, parts.right_cheek, parts.forehead) if p]

    return Measurements(samples=Samples(skin=pooled[0] if pooled else None, skin_parts=parts))


def _lit_by(lightness: float, stops: float) -> float:
    """The same surface under `stops` more light, in L*."""
    import colour
    import numpy as np

    y = relative_luminance(lightness) * (2.0**stops)

    return float(colour.XYZ_to_Lab(np.asarray([0.95047 * y, y, 1.08883 * y]))[0])


# --- the measure -----------------------------------------------------------


def test_stops_are_a_property_of_the_light_not_of_the_skin() -> None:
    """The whole reason this is not measured in L*: one stop is one stop on
    anybody, while the L* gap for that same stop nearly halves on deep skin."""
    light_skin = (_lit_by(75.0, 0.0), _lit_by(75.0, -1.0))
    deep_skin = (_lit_by(35.0, 0.0), _lit_by(35.0, -1.0))

    assert stops_between(*light_skin) == pytest.approx(1.0, abs=0.01)
    assert stops_between(*deep_skin) == pytest.approx(1.0, abs=0.01)

    light_gap = light_skin[0] - light_skin[1]
    deep_gap = deep_skin[0] - deep_skin[1]
    assert light_gap > deep_gap * 1.5, "an L* threshold would treat these two very differently"


def test_the_frame_that_prompted_this(config: EngineConfig) -> None:
    """The live photo: left cheek L* 47.5, right L* 83.5, status ok. Nearly two
    doublings of light across one face."""
    assert stops_between(47.5, 83.5) == pytest.approx(1.94, abs=0.02)

    verdict = uneven_light(_face(47.5, 83.5, 80.0), config)

    assert verdict.reject_reason is RejectReason.UNEVEN_LIGHT


def test_an_ordinary_difference_between_cheeks_is_fine(config: EngineConfig) -> None:
    verdict = uneven_light(_face(70.0, 72.0, 75.0), config)

    assert verdict.reject_reason is None
    assert verdict.confidence_factor == 1.0


# --- the verdicts ----------------------------------------------------------


def test_deep_skin_under_even_light_is_not_uneven(config: EngineConfig) -> None:
    """The mistake this must not make. Deep skin measures dark everywhere; dark
    is not the same as unevenly lit."""
    verdict = uneven_light(_face(28.0, 29.0, 31.0), config)

    assert verdict.reject_reason is None
    assert verdict.confidence_factor == 1.0


def test_deep_skin_lit_from_one_side_is_still_caught(config: EngineConfig) -> None:
    """And the other half of it: the same lighting ratio, on deep skin, where
    the L* gap is much smaller and an L* threshold would have missed it."""
    left = _lit_by(30.0, 0.0)
    right = _lit_by(30.0, 1.4)

    verdict = uneven_light(_face(left, right, (left + right) / 2), config)

    assert right - left < 25.0, "the L* gap here is modest - that is the point"
    assert verdict.reject_reason is RejectReason.UNEVEN_LIGHT


def test_a_borderline_face_is_kept_and_trusted_less(config: EngineConfig) -> None:
    left = _lit_by(65.0, 0.0)
    right = _lit_by(65.0, 0.7)

    verdict = uneven_light(_face(left, right, (left + right) / 2), config)

    assert verdict.reject_reason is None
    assert verdict.confidence_factor == config.confidence_factor_uneven_light
    assert any("trust the reading less" in note for note in verdict.notes)


def test_a_bright_forehead_alone_is_not_a_refusal(config: EngineConfig) -> None:
    """The forehead is the most exposed plane of a face and reads brighter under
    light nobody would call uneven, so its allowance is looser."""
    verdict = uneven_light(_face(68.0, 69.0, 78.0), config)

    assert verdict.reject_reason is None


def test_a_forehead_in_its_own_light_is_refused(config: EngineConfig) -> None:
    cheeks = _lit_by(60.0, 0.0)
    forehead = _lit_by(60.0, 1.8)

    verdict = uneven_light(_face(cheeks, cheeks, forehead), config)

    assert verdict.reject_reason is RejectReason.UNEVEN_LIGHT


# --- missing zones ---------------------------------------------------------


def test_one_cheek_alone_says_nothing_about_evenness(config: EngineConfig) -> None:
    """A face is symmetrical enough that two cheeks are evidence. One is not."""
    verdict = uneven_light(_face(70.0, None, None), config)

    assert verdict.reject_reason is None
    assert verdict.signals["cheek_balance_stops"] is None
    assert verdict.signals["forehead_offset_stops"] is None


def test_the_numbers_are_reported_whether_or_not_they_fired(config: EngineConfig) -> None:
    """A rejection the reviewer cannot audit is a rejection nobody can fix - and
    the same is true of one that did not happen."""
    verdict = uneven_light(_face(70.0, 72.0, 75.0), config)

    assert verdict.signals["cheek_balance_stops"] == pytest.approx(0.1, abs=0.02)
    assert verdict.signals["forehead_offset_stops"] is not None
