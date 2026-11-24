"""The nearest alternative is a neighbour on the wheel, not just the runner-up."""

from __future__ import annotations

import numpy as np

from color_engine.classify import AXES, WHEEL, classify, neighbours
from color_engine.config import EngineConfig

from .test_classifier import _at


def test_the_wheel_holds_each_season_once() -> None:
    assert len(WHEEL) == 12
    assert len(set(WHEEL)) == 12


def test_deep_winter_sits_between_deep_autumn_and_true_winter() -> None:
    assert set(neighbours("deep_winter")) == {"deep_autumn", "true_winter"}
    assert set(neighbours("bright_winter")) == {"true_winter", "bright_spring"}  # the wheel closes


def test_a_deep_winter_is_not_offered_true_summer() -> None:
    # The reading that showed it: moderately deep, slightly cool, chroma on zero.
    result = classify(_at(-0.26, 0.32, 0.0), None, EngineConfig())

    assert result.seasons[0].slug.value == "deep_winter"
    assert result.seasons[1].slug.value in {"deep_autumn", "true_winter"}


def test_the_second_season_is_always_a_neighbour_of_the_first() -> None:
    rng = np.random.default_rng(11)
    for temperature, depth, chroma in rng.uniform(-1.0, 1.0, size=(300, len(AXES))):
        result = classify(_at(temperature, depth, chroma), None, EngineConfig())
        first, second = (s.slug.value for s in result.seasons)

        assert second in neighbours(first), (temperature, depth, chroma)


def test_a_far_runner_up_still_costs_confidence() -> None:
    # Offering a neighbour must not make an ambiguous reading look certain: the
    # margin is still to the best-scoring other season, wherever it sits.
    ambiguous = classify(_at(-0.26, 0.32, 0.0), None, EngineConfig())
    clear = classify(_at(-0.5, 1.0, 0.0), None, EngineConfig())

    assert ambiguous.confidence is not None and clear.confidence is not None
    assert ambiguous.confidence < clear.confidence
