"""The public contract.

Everything in this module is serialised by :meth:`AnalysisResult.to_json` and
consumed over HTTP by Laravel, so treat it as a versioned API: add fields, do
not rename or repurpose them. ``docs/contract.schema.json`` is generated from
these models (``python -m color_engine.models`` regenerates it).

Fields that later pipeline stages fill are declared now and left ``None``.
"""

from __future__ import annotations

import json
from enum import Enum
from pathlib import Path
from typing import Any

from pydantic import BaseModel, ConfigDict, Field


class Status(str, Enum):
    OK = "ok"
    REJECTED = "rejected"


class RejectReason(str, Enum):
    """Why a photo cannot be analysed. Stable strings - Laravel maps them to copy."""

    LOW_RESOLUTION = "low_resolution"
    NO_FACE = "no_face"
    MULTIPLE_FACES = "multiple_faces"
    FACE_TOO_SMALL = "face_too_small"
    FACE_NOT_FRONTAL = "face_not_frontal"
    UNSUPPORTED_FORMAT = "unsupported_format"
    FILE_TOO_LARGE = "file_too_large"
    INSUFFICIENT_SKIN = "insufficient_skin"
    LOW_LIGHT = "low_light"
    UNEVEN_LIGHT = "uneven_light"
    OVEREXPOSED = "overexposed"
    COLOR_CAST = "color_cast"
    MAKEUP_FILTER = "makeup_filter"


class Season(str, Enum):
    """The 12 seasons. Slugs are the contract; never localise these."""

    LIGHT_SPRING = "light_spring"
    TRUE_SPRING = "true_spring"
    BRIGHT_SPRING = "bright_spring"
    LIGHT_SUMMER = "light_summer"
    TRUE_SUMMER = "true_summer"
    SOFT_SUMMER = "soft_summer"
    SOFT_AUTUMN = "soft_autumn"
    TRUE_AUTUMN = "true_autumn"
    DEEP_AUTUMN = "deep_autumn"
    DEEP_WINTER = "deep_winter"
    TRUE_WINTER = "true_winter"
    BRIGHT_WINTER = "bright_winter"


class Metal(str, Enum):
    GOLD = "gold"
    SILVER = "silver"
    BOTH = "both"


class Tanning(str, Enum):
    BURNS = "burns"
    TANS = "tans"
    BOTH = "both"


class Veins(str, Enum):
    BLUE = "blue"
    GREEN = "green"
    MIXED = "mixed"


class Clarity(str, Enum):
    """Which colours the person finds suit them near the face."""

    CLEAR = "clear"
    SOFT = "soft"
    UNSURE = "unsure"


class PreviousHair(str, Enum):
    """The person's natural hair colour from before the dye, as they describe it."""

    LIGHT = "light"
    MEDIUM = "medium"
    DARK = "dark"
    RED = "red"
    GREY = "grey"


class Quiz(BaseModel):
    """Self-reported answers. Optional everywhere: the photo decides, the quiz
    only breaks ties once the classifier exists."""

    model_config = ConfigDict(extra="forbid")

    natural_hair: bool | None = Field(
        None,
        description="False when the hair colour is dyed - hair then carries no seasonal signal.",
    )
    previous_hair: PreviousHair | None = Field(
        None,
        description="Natural hair colour before the dye. Asked only when the hair is dyed. A hint "
        "for the stylist who reads the result: it is recorded in the debug artifacts and never "
        "enters the classification, because the dyed hair is left out of the measurement.",
    )
    metal: Metal | None = Field(None, description="Which metal the person looks better in.")
    tanning: Tanning | None = Field(None, description="How the skin reacts to sun.")
    veins: Veins | None = Field(None, description="Wrist vein colour as the person sees it.")
    clarity: Clarity | None = Field(
        None,
        description="Clear and bright colours or soft and muted ones - which suit the face better, "
        "as the person sees it. Nudges the chroma axis the way the other three nudge temperature.",
    )


class LabSample(BaseModel):
    """One zone, measured in CIELAB (D65, CIE 1931 2 degree observer)."""

    model_config = ConfigDict(extra="forbid")

    L: float = Field(..., description="Median lightness L*, 0..100.")
    a: float = Field(..., description="Median a*, green(-) to red(+).")
    b: float = Field(..., description="Median b*, blue(-) to yellow(+).")
    C: float = Field(..., description="Chroma, hypot(a*, b*), computed from the median a*/b*.")
    h: float = Field(..., description="Hue angle in degrees, 0..360, from the median a*/b*.")
    pixels: int = Field(..., description="Pixels that survived masking and outlier rejection.")
    L_iqr: float = Field(
        ..., description="Interquartile range of L* in the zone - how uniform the sample is."
    )


class SkinParts(BaseModel):
    """The three skin sub-zones, each measured on its own."""

    model_config = ConfigDict(extra="forbid")

    left_cheek: LabSample | None = None
    right_cheek: LabSample | None = None
    forehead: LabSample | None = None


class EyeParts(BaseModel):
    """One eye zone measured on each side separately.

    Left and right are the SUBJECT's, as everywhere in this package - the
    subject's left eye is on the right of a frontal photo.

    These exist because ``zones`` promises a verdict for ``iris_left`` and
    ``iris_right`` and there was nowhere to put the measurement behind it. A
    consumer rendering a row per zone got "not measured" for a side that had in
    fact been measured perfectly well, with no reason beside it, because the
    side was usable and so had nothing to explain.
    """

    model_config = ConfigDict(extra="forbid")

    left: LabSample | None = None
    right: LabSample | None = None


class Samples(BaseModel):
    """Measured colour per zone.

    ``None`` means nothing was measured: the zone was absent, or too little of
    it survived masking to measure at all.

    **Not** "the zone was rejected." A zone that produced a sample keeps its
    numbers here however it was then judged, and ``zones`` carries the verdict
    beside them. They used to be cleared together, which threw away the one
    thing a rejection is worth reviewing on: "darker_than_skin" does not say how
    much darker, so nobody could tell a rule that missed by 1 L* from one that
    missed by 30, and a threshold nobody can see the margin on cannot be argued
    with.

    Nothing downstream measures with a rejected zone - the aggregates and the
    classifier read the verdict, not the presence of numbers.
    """

    model_config = ConfigDict(extra="forbid")

    skin: LabSample | None = Field(
        None,
        description="Pooled sample over the skin sub-zones that were usable. Not always all "
        "three: a blown cheek is measured, reported, and left out of this.",
    )
    skin_parts: SkinParts = Field(default_factory=SkinParts)
    hair: LabSample | None = None
    iris: LabSample | None = Field(
        None,
        description="Both irises pooled - over the pixels each eye kept, and only from the eyes "
        "that passed every check. Never a fresh measurement of the union of the masks, which "
        "would re-admit what each eye's own filtering threw out.",
    )
    iris_parts: EyeParts = Field(
        default_factory=EyeParts,
        description="Each iris on its own, whatever the verdict on it. A side that was rejected "
        "keeps its numbers here and is not in `iris` above.",
    )
    sclera: LabSample | None = Field(None, description="Both scleras pooled, on the same rule.")
    sclera_parts: EyeParts = Field(
        default_factory=EyeParts,
        description="Each sclera on its own, whatever the verdict on it. A side that was rejected "
        "keeps its numbers here and is not in `sclera` above.",
    )


MEASURED_BUT_UNUSED_REASONS: frozenset[str] = frozenset(
    {
        "overexposed",
        "too_dark",
        "too_noisy",
        "not_brighter_than_iris",
        "darker_than_skin",
        "more_chromatic_than_skin",
        "eyes_disagree",
    }
)
"""Reasons a zone can carry while still having numbers in ``samples``.

The rest - ``eye_too_small``, ``too_few_pixels`` - are reasons nothing was
measured, and no amount of keeping samples can produce one.
"""


class ZoneStatus(BaseModel):
    """Whether a zone may be measured WITH, and why not when it may not.

    Not the same question as whether ``samples`` has numbers for it. A blown
    cheek and a sclera that read as lid shadow are both measured and both
    reported; this is what says they were not used.

    "sclera: null" in ``samples`` is not an answer anybody can act on - a closed
    eye, a mask that missed, an eye too small in the original frame and an eye
    whose two halves disagreed all look identical from there. The reason existed
    all along; it just lived in the debug artifacts, where a consumer of the
    contract could not see it.
    """

    model_config = ConfigDict(extra="forbid")

    usable: bool = Field(
        ...,
        description="May this zone be measured WITH? False covers both 'nothing was measured' and "
        "'measured, and then ruled out' - `samples` says which, by whether it has numbers for it.",
    )
    reason: str | None = Field(
        None,
        description="Why not: too_few_pixels, eye_too_small, too_dark, too_noisy, overexposed, "
        "not_brighter_than_iris, darker_than_skin, more_chromatic_than_skin, eyes_disagree. "
        "Null when the zone is usable.",
    )

    @property
    def measured_but_unused(self) -> bool:
        """Rejected on its colour rather than for want of pixels - so `samples`
        has numbers for it. The first four reasons above mean nothing was
        measured; the rest mean something was, and then was not used."""
        return self.reason in MEASURED_BUT_UNUSED_REASONS


class Axes(BaseModel):
    """The three seasonal axes, each in -1..1. Filled by the features stage."""

    model_config = ConfigDict(extra="forbid")

    temperature: float | None = Field(None, ge=-1.0, le=1.0, description="-1 cool .. +1 warm.")
    depth: float | None = Field(None, ge=-1.0, le=1.0, description="-1 light .. +1 deep.")
    chroma: float | None = Field(None, ge=-1.0, le=1.0, description="-1 soft .. +1 bright.")


class Contrast(BaseModel):
    """Contrast measures between zones. Filled by the features stage."""

    model_config = ConfigDict(extra="forbid")

    value: float | None = Field(
        None, description="Overall value contrast: spread of L* across hair, skin and eyes."
    )
    hair_skin: float | None = Field(None, description="L* distance between hair and skin.")
    eye_skin: float | None = Field(None, description="L* distance between iris and skin.")
    iris_sclera: float | None = Field(
        None,
        description="L* distance between the iris and the white of the same eye. The second "
        "contrast the classifier reads, and the one that separates a dark-eyed light blonde from "
        "a light-eyed one.",
    )


class WhiteBalanceMethod(str, Enum):
    """Where the illuminant estimate came from.

    The order is the order of preference: the sclera is the only near-neutral
    reference a face reliably carries, the scene behind the person is the
    fallback, and ``none`` means neither could be trusted and the samples are
    reported as measured. Skin is never a reference - correcting the light
    against it would absorb the undertone the analysis exists to measure.
    """

    SCLERA = "sclera"
    SCENE = "scene"
    NONE = "none"


class WhiteBalanceReason(str, Enum):
    """Why ``method`` came out ``none``.

    ``method: none`` on its own cannot be read. It covers a deployment that
    never wanted correction, a frame whose eyes were unusable, and a frame whose
    background was too dark to estimate from - three different things, only one
    of which is a fact about the photograph. A page showing the first as though
    it were the second sends somebody off to retake a photo that was fine.
    """

    DISABLED = "disabled"
    NO_SCLERA = "sclera_unusable"
    NO_SCENE = "sclera_unusable_and_scene_unusable"
    CAST_SUSPECTED = "cast_suspected_without_an_estimate"


class WhiteBalance(BaseModel):
    """What was done about the light, and how sure we are of it."""

    model_config = ConfigDict(extra="forbid")

    enabled: bool = Field(
        False,
        description="Whether the white-balance step was switched on for this run at all. "
        "Separate from `method` because 'we did not try' and 'we tried and could not' are "
        "different answers, and only one of them is about the photograph.",
    )
    reason: WhiteBalanceReason | None = Field(
        None,
        description="Why `method` is `none`. Null when a correction was applied.",
    )
    method: WhiteBalanceMethod = Field(
        WhiteBalanceMethod.NONE, description="Which reference the illuminant was estimated from."
    )
    single_eye: bool = Field(
        False,
        description="True when only one sclera was usable, so the estimate rests on one eye.",
    )
    correction_strength: float = Field(
        0.0,
        ge=0.0,
        description="How far the estimated illuminant sat from D65, as Euclidean distance in "
        "CIE 1976 u'v'. 0 when nothing was applied. Beyond a threshold the photo is rejected "
        "as color_cast instead of corrected.",
    )
    estimated_cct: float | None = Field(
        None,
        description="Correlated colour temperature of the estimated illuminant in kelvin, or "
        "null when there is no estimate or it sits too far off the Planckian locus to mean "
        "anything.",
    )
    target_calibrated: bool = Field(
        False,
        description="Whether the sclera's target chromaticity has been measured on a real "
        "reference set. False means the correction is pulling the sclera to exact neutral, which "
        "a healthy sclera is not, and every result carries a systematic cool bias of that size. "
        "Consumers should treat an uncalibrated result as provisional.",
    )


class SeasonScore(BaseModel):
    model_config = ConfigDict(extra="forbid")

    slug: Season
    score: float = Field(..., ge=0.0, le=1.0, description="Normalised fit, 1 is best.")


class ExcludedFeature(BaseModel):
    """One input the classifier did not have, and why.

    In the contract rather than only in the debug artifacts, for the same reason
    ``ZoneStatus`` is: a confidence of 0.31 with no explanation is an assertion,
    and the page that has to justify it to a stylist is a consumer of this
    contract, not a reader of our debug objects.
    """

    model_config = ConfigDict(extra="forbid")

    axis: str = Field(..., description="temperature, depth, chroma, or contrast.")
    feature: str = Field(..., description="The feature name in the classifier data file.")
    reason: str = Field(
        ...,
        description="dyed_hair, hair_not_measured, iris_not_measured, sclera_not_measured, "
        "skin_not_measured, skin_too_neutral, contrast_not_measured.",
    )


class ConfidenceBreakdown(BaseModel):
    """The confidence, taken apart into what made it.

    ``final`` is the product of the three. Published whole because a number
    somebody is expected to act on should be arguable, and it cannot be argued
    with unless the argument is visible.
    """

    model_config = ConfigDict(extra="forbid")

    margin: float = Field(
        0.0,
        ge=0.0,
        le=1.0,
        description="The RAW gap between the top two scores. Zero on a dead heat. Reported "
        "beside `from_margin` rather than folded into it, because the two are different "
        "numbers and a page showing one under the other's name is worse than showing neither: "
        "the first live frame had two seasons tied at 0.1445 and the label 'how far the first "
        "season is ahead of the second' read 0.150.",
    )
    from_margin: float = Field(
        ...,
        ge=0.0,
        le=1.0,
        description="That gap put through the confidence curve. Never zero - a dead heat still "
        "earns the curve's floor, because the season is still the best available reading.",
    )
    features_factor: float = Field(
        ..., gt=0.0, le=1.0, description="What the missing or excluded zones cost."
    )
    quality_factor: float = Field(
        ...,
        gt=0.0,
        le=1.0,
        description="What the photograph cost: white balance, exposure, "
        "uneven light - everything the earlier stages concluded, multiplied together.",
    )
    final: float = Field(..., ge=0.0, le=1.0, description="The product, and what `confidence` is.")


class Classifier(BaseModel):
    """Which classifier decided the season, and how far to trust it.

    Present only when a season was actually computed - a refused photo has no
    seasons and nothing to say about them.
    """

    model_config = ConfigDict(extra="forbid")

    version: str = Field(
        ...,
        description="Version of the classifier DATA (src/color_engine/data/classifier.json), not "
        "of the package. Every anchor point, weight and prototype lives there, so this is the "
        "thing that changes when seasons start coming out differently.",
    )
    draft: bool = Field(
        ...,
        description="True while the anchor points and prototypes are reasoned rather than "
        "measured. Nothing in this classifier has been checked against faces a stylist labelled, "
        "because there is no such set yet. Consumers must say so to the user.",
    )
    low_confidence: bool = Field(
        False,
        description="Confidence fell below the data file's min_confidence_for_season. The season "
        "is still the best available reading - this says how much of one it is.",
    )
    exclusions: list[ExcludedFeature] = Field(
        default_factory=list,
        description="Every feature the classifier could not use, with its reason. Empty when it "
        "had everything.",
    )
    confidence: ConfidenceBreakdown | None = Field(
        None, description="How the confidence was arrived at. Null when no season was placed."
    )


class AnalysisResult(BaseModel):
    """What ``Engine.analyze`` returns and what the HTTP layer will serve.

    Non-contract extras (debug artifacts, step timings) live in private
    attributes exposed as properties, so they never leak into ``to_json``.
    """

    model_config = ConfigDict(extra="forbid")

    status: Status
    reject_reason: RejectReason | None = Field(
        None, description="Set if and only if status == 'rejected'."
    )
    source_color_space: str | None = Field(
        None, description="ICC profile found in the input, or 'sRGB (assumed)' when untagged."
    )
    samples: Samples = Field(
        default_factory=Samples,
        description="Measured colour per zone, already adapted to D65 when white balance was "
        "applied. The uncorrected values live in the debug artifacts, never in the contract.",
    )
    white_balance: WhiteBalance = Field(default_factory=WhiteBalance)
    zones: dict[str, ZoneStatus] = Field(
        default_factory=dict,
        description="Per-zone verdict, keyed by the engine's own zone names: skin, left_cheek, "
        "right_cheek, forehead, hair, iris, iris_left, iris_right, sclera, sclera_left, "
        "sclera_right. Empty when the photo was refused before any zone was built.",
    )
    axes: Axes = Field(default_factory=Axes)
    contrast: Contrast = Field(default_factory=Contrast)
    seasons: list[SeasonScore] = Field(
        default_factory=list, description="Top 2 seasons, best first. Empty until classification."
    )
    confidence: float | None = Field(
        None, ge=0.0, le=1.0, description="How much to trust the season call."
    )
    classifier: Classifier | None = Field(
        None, description="Which classifier produced the seasons. Null when none ran."
    )
    algo_version: str = Field(..., description="Package version that produced this result.")

    def to_json(self, *, indent: int | None = None) -> str:
        return json.dumps(self.to_dict(), indent=indent, ensure_ascii=False)

    def to_dict(self) -> dict[str, Any]:
        return self.model_dump(mode="json")

    @property
    def rejected(self) -> bool:
        return self.status is Status.REJECTED

    # --- non-contract, never serialised -------------------------------------

    @property
    def debug(self) -> Any:
        """``DebugArtifacts`` when analyze(debug=True) was used, else ``None``."""
        return getattr(self, "_debug_artifacts", None)

    @property
    def timings_ms(self) -> dict[str, float]:
        return dict(getattr(self, "_timings_ms", None) or {})

    def attach_diagnostics(self, debug: Any, timings_ms: dict[str, float]) -> AnalysisResult:
        object.__setattr__(self, "_debug_artifacts", debug)
        object.__setattr__(self, "_timings_ms", timings_ms)
        return self


CONTRACT_SCHEMA_PATH = Path(__file__).resolve().parent / "docs" / "contract.schema.json"


def contract_schema() -> dict[str, Any]:
    """JSON Schema for exactly what ``to_json`` produces."""
    schema: dict[str, Any] = AnalysisResult.model_json_schema(mode="serialization")
    schema["$schema"] = "https://json-schema.org/draft/2020-12/schema"
    schema["title"] = "ColorEngineAnalysisResult"
    return schema


def write_contract_schema(path: Path | None = None) -> Path:
    target = path or CONTRACT_SCHEMA_PATH
    target.parent.mkdir(parents=True, exist_ok=True)
    # LF whatever the platform: this file is committed, and a regeneration on
    # Windows must not show up as six hundred changed lines.
    target.write_text(
        json.dumps(contract_schema(), indent=2) + "\n", encoding="utf-8", newline="\n"
    )
    return target


if __name__ == "__main__":  # pragma: no cover - maintenance helper
    print(write_contract_schema())
