"""Season classification.

The 12 seasons are 12 points in the (temperature, depth, chroma) cube. Each one
sits at the far end of its LEADING axis and half way along its SECONDARY, which
is what the system's own vocabulary says they are: a light spring is light
first and warm second, a deep winter is deep first and cool second. The third
axis is left at zero.

A face is scored against all 12 by weighted distance - each season weighting the
axes by what it is named for - and the distances become scores through a softmax.
Softmax rather than "closest wins": a face sitting exactly between two
neighbours should come out as a near-tie between them, because that is what it
is, and the tie is what makes the confidence come out low.

Confidence starts from the margin between the top two and is then multiplied
down by everything the pipeline already knows was wrong with the photograph -
no white balance, low light, light from one side, dyed hair, one usable iris.
Those multipliers are collected in one place, :func:`classify`, and the whole
product is written into the debug artifacts, so a confidence of 0.31 can always
be taken apart into the four things that made it 0.31.

Every number here is loaded from ``data/classifier.json``. Nothing in this
module has been validated against faces a stylist labelled, because no such set
exists yet - see :mod:`color_engine.classifier_data`.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field
from typing import Any

from .classifier_data import ClassifierData, interpolate, load_classifier_data
from .config import EngineConfig
from .features import Features
from .models import (
    Axes,
    Classifier,
    ConfidenceBreakdown,
    ExcludedFeature,
    Quiz,
    Season,
    SeasonScore,
)

AXES: tuple[str, ...] = ("temperature", "depth", "chroma")

TOP_N = 2
"""How many seasons come back. Two, because the neighbouring season is a real
call for anybody sitting on a boundary and the stylist wants to see it."""

WHEEL: tuple[str, ...] = (
    "bright_spring",
    "true_spring",
    "light_spring",
    "light_summer",
    "true_summer",
    "soft_summer",
    "soft_autumn",
    "true_autumn",
    "deep_autumn",
    "deep_winter",
    "true_winter",
    "bright_winter",
)
"""The twelve seasons in the order they sit round the colour wheel, as the
stylist's own chart lays them out: each shares its leading or its secondary
characteristic with the two beside it (deep autumn and deep winter are both deep;
deep winter and true winter are both cool). The second season a person is shown
is one of the winner's two neighbours: a true summer is a long way from a deep
winter, and "your nearest alternative" has to be a season you can actually be
torn between."""


def neighbours(slug: str) -> tuple[str, str]:
    """The two seasons beside ``slug`` on the wheel."""
    index = WHEEL.index(slug)

    return WHEEL[(index - 1) % len(WHEEL)], WHEEL[(index + 1) % len(WHEEL)]


@dataclass(frozen=True)
class Classification:
    seasons: list[SeasonScore] = field(default_factory=list)
    confidence: float | None = None
    info: Classifier | None = None
    debug: dict[str, Any] = field(default_factory=dict)


def classify(
    features: Features,
    quiz: Quiz | None,
    config: EngineConfig,
    quality_factor: float = 1.0,
    data: ClassifierData | None = None,
) -> Classification:
    """Score the 12 seasons and return the best two.

    ``quality_factor`` is everything the earlier stages concluded about the
    photograph, already multiplied together: white balance, exposure, even
    lighting. It arrives as one number because the alternative - this function
    reaching back into three other stages' verdicts - would put the definition
    of confidence in four places at once.
    """
    del config, quiz  # the quiz has already had its say, on temperature
    rules = data or load_classifier_data()

    axes = features.axes
    if all(getattr(axes, name) is None for name in AXES):
        # Nothing measurable to classify on. Better no season than one derived
        # from three defaults.
        return Classification(debug={"reason": "no_axes"})

    distances = {slug: _distance(axes, slug, rules) for slug in sorted(rules.seasons.prototypes)}
    scores = _softmax(distances, rules.seasons.temperature)

    ranked = sorted(scores.items(), key=lambda item: (-item[1], item[0]))
    margin = ranked[0][1] - ranked[1][1] if len(ranked) > 1 else 1.0

    from_margin = interpolate(rules.confidence.margin_anchors, margin)
    confidence = from_margin * features.confidence_factor * quality_factor
    confidence = round(max(0.0, min(1.0, confidence)), 4)

    breakdown = ConfidenceBreakdown(
        margin=round(max(0.0, margin), 4),
        from_margin=round(from_margin, 4),
        features_factor=round(features.confidence_factor, 4),
        quality_factor=round(min(1.0, quality_factor), 4),
        final=confidence,
    )
    info = Classifier(
        version=rules.version,
        draft=rules.draft,
        low_confidence=confidence < rules.confidence.min_confidence_for_season,
        # The same list the debug artifacts carry. Duplicated on purpose: the
        # page owes a stylist an explanation, and it reads the contract.
        exclusions=[
            ExcludedFeature(axis=item.axis, feature=item.feature, reason=item.reason)
            for item in features.exclusions
        ],
        confidence=breakdown,
    )

    debug = {
        "scores": {slug: round(score, 4) for slug, score in ranked},
        "distances": {slug: round(value, 4) for slug, value in sorted(distances.items())},
        "margin": round(margin, 4),
        # The arithmetic, spelled out, so a low number can be argued with.
        # The contract carries the same three factors; this adds the threshold
        # they were judged against, which is a property of the data file rather
        # than of this photograph.
        "confidence": {
            **breakdown.model_dump(mode="json"),
            "threshold": rules.confidence.min_confidence_for_season,
        },
        **features.as_debug(),
    }

    # The winner, then the better of its two neighbours - not simply the
    # runner-up. How close the runner-up really is stays in the margin, and so in
    # the confidence: a far-off season scoring close behind makes the reading
    # uncertain without becoming the "alternative".
    near = set(neighbours(ranked[0][0]))
    alternative = next(item for item in ranked[1:] if item[0] in near)
    seasons = [
        SeasonScore(slug=Season(slug), score=round(score, 4))
        for slug, score in [ranked[0], alternative][:TOP_N]
    ]
    return Classification(seasons=seasons, confidence=confidence, info=info, debug=debug)


def prototype(slug: str, rules: ClassifierData) -> dict[str, float]:
    """Where a season sits in the cube: leading axis out at the end, secondary
    half way, third at zero."""
    spec = rules.seasons.prototypes[slug]
    point = dict.fromkeys(AXES, 0.0)
    lead_axis, lead_sign = spec.leading
    second_axis, second_sign = spec.secondary
    point[lead_axis] = rules.seasons.leading_value * lead_sign
    point[second_axis] = rules.seasons.secondary_value * second_sign
    return point


def weights(slug: str, rules: ClassifierData) -> dict[str, float]:
    """How much each axis counts for one season.

    The leading axis dominates because it is what the season is named for; the
    secondary is what separates the seasons sharing that lead; the third is
    nearly ignored but not quite. Not quite, because at exactly zero two
    prototypes lying on the same plane would tie to the last decimal, and a tie
    is the one answer that helps nobody.
    """
    spec = rules.seasons.prototypes[slug]
    out = dict.fromkeys(AXES, rules.seasons.weights.third)
    out[spec.leading[0]] = rules.seasons.weights.leading
    out[spec.secondary[0]] = rules.seasons.weights.secondary
    return out


def _distance(axes: Axes, slug: str, rules: ClassifierData) -> float:
    """Weighted RMS distance to one prototype, over the axes we actually have.

    Dividing by the weight in play rather than by a constant is what lets a face
    with no usable depth still be classified: it is compared to every prototype
    on the axes that exist, and the ones that differ only in depth simply end up
    tied - which the margin then reports as low confidence, correctly.
    """
    point = prototype(slug, rules)
    per_axis = weights(slug, rules)

    total = 0.0
    total_weight = 0.0
    for name in AXES:
        value = getattr(axes, name)
        if value is None:
            continue
        total += per_axis[name] * (value - point[name]) ** 2
        total_weight += per_axis[name]

    if total_weight == 0.0:  # pragma: no cover - guarded by the caller
        return 0.0

    return math.sqrt(total / total_weight)


def _softmax(distances: dict[str, float], temperature: float) -> dict[str, float]:
    """Distances to scores summing to 1.

    Shifted by the smallest distance before exponentiating - standard defence
    against ``exp`` underflowing, and it leaves the ratios untouched.
    """
    closest = min(distances.values())
    weighted = {
        slug: math.exp(-(value - closest) / temperature) for slug, value in distances.items()
    }
    total = sum(weighted.values())
    return {slug: value / total for slug, value in weighted.items()}
