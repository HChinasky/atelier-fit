"""The classifier, as data.

Every number that decides a season - anchor points, weights, prototypes, the
cost of a missing zone - lives in ``data/classifier.json`` and is loaded here.
None of it is in the code.

That split is the point, and it is worth being precise about where it falls:

* **Data** says how much a feature counts and where its anchors sit. Re-weighting
  the axes, moving a prototype, making dyed hair cheaper - all of it is a diff in
  a JSON file that a stylist can read and argue with.
* **Code** says how to get a number out of a face. Adding a feature nobody has
  measured before - say, the hue of the lips - needs an extractor in
  :mod:`color_engine.features`, because there is nothing in a JSON file that can
  invent a measurement.

Changing the data means changing ``version`` with it: a test compares the file's
hash against the version, so an edit that quietly changes what everybody's
season is cannot ship as "no functional change". :func:`data_hash` is what that
test reads.

Prose is welcome in the file. Unknown keys are ignored rather than rejected, so
a ``why`` can be added anywhere without a code change - and every number in
there has one, because a threshold whose reasoning was never written down is a
threshold nobody can revise.
"""

from __future__ import annotations

import hashlib
import json
from functools import lru_cache
from pathlib import Path

from pydantic import BaseModel, ConfigDict, Field

CLASSIFIER_DATA_PATH = Path(__file__).resolve().parent / "data" / "classifier.json"

Anchor = tuple[float, float]


class _Spec(BaseModel):
    """Ignores what it does not know, so the file can carry its own reasoning."""

    model_config = ConfigDict(extra="ignore")


class FeatureSpec(_Spec):
    weight: float = Field(..., gt=0.0)
    anchors: list[Anchor] = Field(..., min_length=2)
    min_chroma: float | None = Field(
        None,
        description="Skip this feature when the zone's C* is below this. Only meaningful for a "
        "hue feature: the hue angle of a near-neutral sample is the direction of its noise.",
    )


class QuizSpec(_Spec):
    max_shift: float = Field(..., ge=0.0, le=1.0)
    may_flip_sign: bool = False
    answers: dict[str, dict[str, float]]
    question_weights: dict[str, float] = Field(default_factory=dict)
    """Relative say of each question when several are answered. A question not
    listed counts 1, so a file without this key behaves as before."""


class ReliabilitySpec(_Spec):
    """How much a zone's tone reading is worth, from its own chroma."""

    anchors: list[Anchor] = Field(..., min_length=2)


class AxisSpec(_Spec):
    description: str = ""
    features: dict[str, FeatureSpec]
    quiz: QuizSpec | None = None
    zone_reliability: ReliabilitySpec | None = None
    """When present, every feature's weight is multiplied by this curve applied
    to its own zone's C*, and the survivors are renormalised."""


class ContrastSpec(_Spec):
    description: str = ""
    features: dict[str, FeatureSpec]


class MissingDataSpec(_Spec):
    dyed_hair_confidence: float = Field(..., gt=0.0, le=1.0)
    no_hair_confidence: float = Field(..., gt=0.0, le=1.0)
    no_iris_confidence: float = Field(..., gt=0.0, le=1.0)
    no_sclera_confidence: float = Field(..., gt=0.0, le=1.0)


class PrototypeSpec(_Spec):
    leading: tuple[str, float]
    secondary: tuple[str, float]


class SeasonWeights(_Spec):
    leading: float = Field(..., gt=0.0)
    secondary: float = Field(..., gt=0.0)
    third: float = Field(..., gt=0.0)


class SeasonsSpec(_Spec):
    weights: SeasonWeights
    leading_value: float = Field(..., gt=0.0, le=1.0)
    secondary_value: float = Field(..., gt=0.0, le=1.0)
    prototypes: dict[str, PrototypeSpec]
    temperature: float = Field(..., gt=0.0, description="Softmax temperature over the distances.")


class ConfidenceSpec(_Spec):
    margin_anchors: list[Anchor] = Field(..., min_length=2)
    min_confidence_for_season: float = Field(..., ge=0.0, le=1.0)


class ClassifierData(_Spec):
    version: str
    draft: bool
    axes: dict[str, AxisSpec]
    contrast: ContrastSpec
    missing_data: MissingDataSpec
    seasons: SeasonsSpec
    confidence: ConfidenceSpec


@lru_cache(maxsize=1)
def load_classifier_data(path: Path | None = None) -> ClassifierData:
    """The validated data file. Cached - it never changes inside a process."""
    target = path or CLASSIFIER_DATA_PATH
    return ClassifierData.model_validate(json.loads(target.read_text(encoding="utf-8")))


@lru_cache(maxsize=1)
def data_hash(path: Path | None = None) -> str:
    """SHA-256 of the data file, with line endings normalised and nothing else.

    Over the bytes and not over the parsed object on purpose: reformatting is
    also a change somebody should have to look at, and a hash that forgives
    whitespace is a hash that forgives a stray minus sign in a reflowed line.

    Line endings are the one exception, and they have to be. This repository
    stores LF and checks out through ``core.autocrlf``, so the same committed
    file is CRLF on one machine and LF on another - a hash over the literal
    bytes would fail on Windows, or in CI, depending on where it was recorded.
    That is a property of the checkout, not an edit anybody made.
    """
    target = path or CLASSIFIER_DATA_PATH
    return hashlib.sha256(target.read_bytes().replace(b"\r\n", b"\n")).hexdigest()


def interpolate(anchors: list[Anchor], value: float) -> float:
    """Piecewise-linear through the anchors, clamped at both ends.

    Anchors may run either way along x - ``hair_L`` maps 70 to -1 and 20 to +1,
    because light hair is a light season - so they are sorted before use rather
    than assumed to ascend.

    Clamped and not extrapolated: past the last anchor the feature has said
    everything it has to say. Hair at L* 8 is not more than as deep as hair at
    L* 20, and an axis that runs off to -4 would drown every other feature in
    the weighted mean.
    """
    ordered = sorted(anchors)
    xs = [x for x, _ in ordered]
    ys = [y for _, y in ordered]

    if value <= xs[0]:
        return ys[0]
    if value >= xs[-1]:
        return ys[-1]

    for left in range(len(xs) - 1):
        if value <= xs[left + 1]:
            span = xs[left + 1] - xs[left]
            if span == 0:
                return ys[left + 1]
            t = (value - xs[left]) / span
            return ys[left] + t * (ys[left + 1] - ys[left])

    return ys[-1]  # pragma: no cover - unreachable, the loop covers the range
