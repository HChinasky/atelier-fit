"""Seasonal axes and contrast.

Three axes, each in -1..1, plus the contrast between zones:

* ``temperature`` -1 cool .. +1 warm, led by the hue angle of skin;
* ``depth`` -1 light .. +1 deep, led by the L* of hair;
* ``chroma`` -1 soft .. +1 bright, led by contrast and the saturation of the eye.

Deterministic arithmetic, no model. Every weight and every anchor point comes
from ``data/classifier.json`` (see :mod:`color_engine.classifier_data`); this
module only knows how to pull a number off a face and how to combine numbers
once the data file has said what they are worth.

Each feature is mapped through its own anchor points - a triple saying which
value means -1, which means 0 and which means +1 - and the axis is the weighted
mean of whatever survived. "Whatever survived" is the interesting part: a face
routinely arrives with no usable sclera, or with hair that has been dyed, and an
axis computed as though the missing zone had been average would be a confident
answer built on a zone nobody measured. Instead the feature drops out, the
remaining weights renormalise, the exclusion is recorded with its reason, and
the confidence it would have carried is taken off the result.

The quiz is a nudge on temperature and nothing else. It is capped by
``max_shift`` and cannot carry the value across zero: a person who has been told
for years that they are "a winter" answers like a winter, so a quiz that could
outvote the photograph would be measuring the belief instead of the face.
"""

from __future__ import annotations

from collections.abc import Callable, Container
from dataclasses import dataclass, field
from typing import Any

from .classifier_data import (
    ClassifierData,
    FeatureSpec,
    QuizSpec,
    interpolate,
    load_classifier_data,
)
from .config import EngineConfig
from .models import Axes, Contrast, LabSample, Quiz, Samples

# --- why a feature did not make it into an axis ----------------------------

DYED_HAIR = "dyed_hair"
HAIR_NOT_MEASURED = "hair_not_measured"
IRIS_NOT_MEASURED = "iris_not_measured"
SCLERA_NOT_MEASURED = "sclera_not_measured"
SKIN_NOT_MEASURED = "skin_not_measured"
SKIN_TOO_NEUTRAL = "skin_too_neutral"
OVEREXPOSED = "overexposed"
NO_CONTRAST = "contrast_not_measured"


@dataclass(frozen=True)
class Exclusion:
    """One feature that was left out, and why.

    Kept per feature rather than per zone because that is the resolution the
    question is asked at: "dyed hair" costs temperature one input out of four
    and depth one out of three, and those are different amounts of damage.
    """

    axis: str
    feature: str
    reason: str

    def as_dict(self) -> dict[str, str]:
        return {"axis": self.axis, "feature": self.feature, "reason": self.reason}


@dataclass(frozen=True)
class Features:
    axes: Axes
    contrast: Contrast
    exclusions: list[Exclusion] = field(default_factory=list)
    confidence_factor: float = 1.0
    """What the exclusions cost, as a multiplier - applied by the classifier."""
    quiz_shift: float = 0.0
    """How far the quiz actually moved temperature, after capping and clamping."""
    chroma_quiz_shift: float = 0.0
    """The same for the chroma axis (the "clarity" question)."""
    previous_hair: str | None = None
    """The natural colour a person with dyed hair says they had. Recorded for the
    stylist; nothing in the classification reads it."""

    def as_debug(self) -> dict[str, Any]:
        return {
            "exclusions": [item.as_dict() for item in self.exclusions],
            "confidence_factor": round(self.confidence_factor, 4),
            "quiz_shift": round(self.quiz_shift, 4),
            "chroma_quiz_shift": round(self.chroma_quiz_shift, 4),
            "previous_hair": self.previous_hair,
        }


# --- getting a number off a face -------------------------------------------
#
# A feature name in the data file is looked up here. This table is the reason
# adding a NEW KIND of measurement needs code while re-weighting an existing one
# does not: there is nothing a JSON file can say that invents a lip sample.

Extractor = Callable[[Samples], LabSample | None]

_ZONE: dict[str, Extractor] = {
    "skin": lambda s: s.skin,
    "hair": lambda s: s.hair,
    "iris": lambda s: s.iris,
    "sclera": lambda s: s.sclera,
}

_COMPONENT: dict[str, Callable[[LabSample], float]] = {
    "L": lambda sample: sample.L,
    "a": lambda sample: sample.a,
    "b": lambda sample: sample.b,
    "C": lambda sample: sample.C,
    "hue": lambda sample: sample.h,
}

_MISSING_ZONE_REASON = {
    "skin": SKIN_NOT_MEASURED,
    "hair": HAIR_NOT_MEASURED,
    "iris": IRIS_NOT_MEASURED,
    "sclera": SCLERA_NOT_MEASURED,
}


def compute_features(
    samples: Samples,
    quiz: Quiz | None,
    config: EngineConfig,
    data: ClassifierData | None = None,
    unusable: Container[str] = frozenset(),
) -> Features:
    """The three axes and the contrast, from the samples as they were measured.

    ``unusable`` names the zones whose samples are reported but must not be
    MEASURED with - an overexposed cheek, most often. Its numbers stay in the
    contract because they are the evidence a reviewer needs; they simply do not
    get a vote, and the exclusion says why.

    ``samples`` is whatever the engine decided to classify on: corrected when
    white balance was applied, as measured when it was not. That choice belongs
    upstream and this module does not second-guess it - but it is why an
    uncorrected result should be read as provisional, since every hue angle in
    here still carries the colour of the room.
    """
    del config  # everything that shapes an axis lives in the data file
    rules = data or load_classifier_data()
    excluded: list[Exclusion] = []
    penalties: list[float] = []

    dyed = quiz is not None and quiz.natural_hair is False
    if dyed:
        penalties.append(rules.missing_data.dyed_hair_confidence)

    # Contrast first: the chroma axis is partly built on it.
    contrast = _contrast(samples, rules, excluded, dyed, unusable)

    available: dict[str, float | None] = {"contrast": contrast.value}
    axes_values: dict[str, float | None] = {}
    for name, spec in rules.axes.items():
        axes_values[name] = _axis(name, spec, samples, available, excluded, dyed, unusable)

    shifts = {"temperature": 0.0, "chroma": 0.0}
    for name in shifts:
        value = axes_values.get(name)
        spec = rules.axes[name].quiz if name in rules.axes else None
        if value is not None:
            axes_values[name], shifts[name] = _nudge(value, quiz, spec)

    penalties.extend(_zone_penalties(samples, rules, dyed))

    factor = 1.0
    for penalty in penalties:
        factor *= penalty

    return Features(
        axes=Axes(
            temperature=_round(axes_values.get("temperature")),
            depth=_round(axes_values.get("depth")),
            chroma=_round(axes_values.get("chroma")),
        ),
        contrast=contrast,
        exclusions=excluded,
        confidence_factor=round(factor, 4),
        quiz_shift=round(shifts["temperature"], 4),
        chroma_quiz_shift=round(shifts["chroma"], 4),
        previous_hair=quiz.previous_hair.value if dyed and quiz.previous_hair is not None else None,
    )


# --- axes -------------------------------------------------------------------


def _axis(
    axis: str,
    spec: Any,
    samples: Samples,
    available: dict[str, float | None],
    excluded: list[Exclusion],
    dyed: bool,
    unusable: Container[str],
) -> float | None:
    """Weighted mean of the features that survived, or None if none did.

    The mean divides by the weight that was actually used, which is the whole
    of "weights are redistributed": dropping hair from depth does not shrink
    depth towards zero, it hands hair's half to skin and iris in the proportion
    they already stood in.
    """
    total_weight = 0.0
    total = 0.0

    for name, feature in spec.features.items():
        value, reason = _feature_value(name, feature, samples, available, dyed, unusable)
        if value is None:
            excluded.append(Exclusion(axis, name, reason or SKIN_NOT_MEASURED))
            continue
        weight = feature.weight * _reliability(name, spec, samples)
        total += weight * interpolate(feature.anchors, value)
        total_weight += weight

    if total_weight == 0.0:
        return None

    return max(-1.0, min(1.0, total / total_weight))


def _reliability(name: str, spec: Any, samples: Samples) -> float:
    """How much this feature's zone has earned its weight, from its own C*.

    Hue is an ANGLE, and the angle of a near-neutral sample is the direction of
    its noise - so a fixed weight treats a guess and a measurement as equals. On
    the first classified frame the skin arrived at C* 9.9 holding 0.45 of the
    temperature axis while saturated natural red hair at C* 23.8 held 0.15, and
    the hair was much the better evidence.

    One curve for every zone. A zone earns its say by how much colour it
    actually has, and nothing in here knows what a redhead is: the same
    arithmetic demotes washed-out hair on a face whose skin is strongly coloured.

    Only axes that declare ``zone_reliability`` are scaled, and features that
    are not a zone reading - ``contrast``, which is already a derived 0..1
    figure - are left alone.
    """
    curve = getattr(spec, "zone_reliability", None)
    if curve is None:
        return 1.0

    zone_name, _, _ = name.partition("_")
    zone = _ZONE.get(zone_name)
    if zone is None:
        return 1.0

    sample = zone(samples)
    if sample is None:  # pragma: no cover - the caller already dropped it
        return 1.0

    return interpolate(curve.anchors, sample.C)


def _feature_value(
    name: str,
    feature: FeatureSpec,
    samples: Samples,
    available: dict[str, float | None],
    dyed: bool,
    unusable: Container[str],
) -> tuple[float | None, str | None]:
    """The raw measurement behind one feature name, before its anchors."""
    if name in available:
        value = available[name]
        return (value, None) if value is not None else (None, NO_CONTRAST)

    zone_name, _, component = name.partition("_")
    zone = _ZONE.get(zone_name)
    if zone is None or component not in _COMPONENT:  # pragma: no cover - data typo
        raise KeyError(f"no extractor for classifier feature {name!r}")

    # Dye tells you about a decision, not about a person. Hair carries half of
    # depth and a fifth of temperature, so leaving it in would put the colourist
    # in charge of the season.
    if zone_name == "hair" and dyed:
        return None, DYED_HAIR

    # A zone the camera stopped recording. Its sample is still in the contract -
    # the numbers are what show a reviewer WHY it was dropped - but a ceiling is
    # not a colour and does not get a vote.
    if zone_name in unusable:
        return None, OVEREXPOSED

    sample = zone(samples)
    if sample is None:
        return None, _MISSING_ZONE_REASON[zone_name]

    # Via a local because ruff reads the attribute `.C` as a constant and calls
    # the comparison a Yoda condition either way round.
    chroma = sample.C
    floor = feature.min_chroma
    if floor is not None and chroma < floor:
        return None, SKIN_TOO_NEUTRAL

    return _COMPONENT[component](sample), None


def _nudge(value: float, quiz: Quiz | None, spec: QuizSpec | None) -> tuple[float, float]:
    """Move an axis by the quiz, within the cap and without crossing zero.

    The questions an axis listens to are the spec's own ``answers`` keys, each
    read off the quiz by name - temperature hears metal, veins and tanning,
    chroma hears clarity - so a new question is data plus a Quiz field, not a
    new branch here.

    Answered questions are averaged (weighted by ``question_weights``) rather than summed: somebody who answered
    one of three should not be moved a third as far as somebody who answered all
    three in the same direction, they should be moved the same distance with
    less to back it up - and "less to back it up" is confidence's job, not this
    function's.
    """
    if spec is None or quiz is None:
        return value, 0.0

    votes: list[tuple[float, float]] = []
    for question, weights in spec.answers.items():
        answer = getattr(quiz, question, None)
        if answer is not None and answer.value in weights:
            votes.append((weights[answer.value], spec.question_weights.get(question, 1.0)))
    if not votes:
        return value, 0.0

    # Weighted by how well the stylist says each question tells the thing it
    # is about; with no weights in the data every question counts 1 and this is
    # the plain average it used to be.
    total_weight = sum(weight for _, weight in votes)
    vote = sum(answer * weight for answer, weight in votes) / total_weight
    shifted = value + vote * spec.max_shift
    if not spec.may_flip_sign:
        shifted = max(shifted, 0.0) if value > 0 else shifted
        shifted = min(shifted, 0.0) if value < 0 else shifted

    shifted = max(-1.0, min(1.0, shifted))
    return shifted, shifted - value


# --- contrast ---------------------------------------------------------------


def _contrast(
    samples: Samples,
    rules: ClassifierData,
    excluded: list[Exclusion],
    dyed: bool,
    unusable: Container[str] = frozenset(),
) -> Contrast:
    """How far apart a person's own colours sit.

    ``value`` is the 0..1 figure the chroma axis reads. The three raw distances
    beside it are in L* and are reported for the report's sake - a number
    somebody can check against the samples table with their own eyes.
    """

    # Only over zones that are still a measurement. A blown cheek left in
    # inflates every distance it appears in, and contrast feeds the chroma axis
    # - which is how one overexposed patch becomes a season.
    def usable(name: str, sample: LabSample | None) -> LabSample | None:
        return None if name in unusable else sample

    skin = usable("skin", samples.skin)
    hair = usable("hair", samples.hair)
    iris = usable("iris", samples.iris)
    sclera = usable("sclera", samples.sclera)

    raw = {
        "hair_skin": _distance(hair, skin, exclude=dyed),
        "iris_sclera": _distance(iris, sclera),
        "eye_skin": _distance(iris, skin),
    }

    total_weight = 0.0
    total = 0.0
    for name, feature in rules.contrast.features.items():
        value = raw.get(name)
        if value is None:
            excluded.append(
                Exclusion("contrast", name, _contrast_reason(name, samples, dyed, unusable))
            )
            continue
        total += feature.weight * interpolate(feature.anchors, value)
        total_weight += feature.weight

    overall = None if total_weight == 0.0 else max(0.0, min(1.0, total / total_weight))

    return Contrast(
        value=_round(overall),
        hair_skin=_round(raw["hair_skin"]),
        eye_skin=_round(raw["eye_skin"]),
        iris_sclera=_round(raw["iris_sclera"]),
    )


def _distance(
    one: LabSample | None, other: LabSample | None, *, exclude: bool = False
) -> float | None:
    if exclude or one is None or other is None:
        return None
    return abs(one.L - other.L)


def _contrast_reason(name: str, samples: Samples, dyed: bool, unusable: Container[str]) -> str:
    if name == "hair_skin":
        if dyed:
            return DYED_HAIR
        if "hair" in unusable or "skin" in unusable:
            return OVEREXPOSED
        return HAIR_NOT_MEASURED if samples.hair is None else SKIN_NOT_MEASURED
    if "iris" in unusable or "sclera" in unusable:
        return OVEREXPOSED
    if samples.iris is None:
        return IRIS_NOT_MEASURED
    return SCLERA_NOT_MEASURED


# --- confidence -------------------------------------------------------------


def _zone_penalties(samples: Samples, rules: ClassifierData, dyed: bool) -> list[float]:
    """What the missing zones cost, once each.

    Once each and not once per feature: a face with no iris has lost one zone,
    not three, and multiplying the penalty by however many axes happened to
    mention it would punish the shape of the data file rather than the
    photograph.
    """
    missing = rules.missing_data
    penalties = []
    if samples.hair is None and not dyed:
        penalties.append(missing.no_hair_confidence)
    if samples.iris is None:
        penalties.append(missing.no_iris_confidence)
    if samples.sclera is None:
        penalties.append(missing.no_sclera_confidence)
    return penalties


def _round(value: float | None) -> float | None:
    return None if value is None else round(value, 4)
