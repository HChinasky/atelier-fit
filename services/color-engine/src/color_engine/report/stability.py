"""How far a person's measurements drift between lighting conditions.

This is the instrument stage 2 is judged by. The question it answers is not "is
the season right" but "does the same face measure the same under a window and
under a tungsten bulb" - and now, "how much of that gap did white balance
close". Every number is reported twice, **before** and **after** the illuminant
was divided out, so the correction can be credited or blamed rather than
assumed.

It needs no seasonal ground truth - only ``person_id`` and ``lighting``, from
``labels.json`` or from the filenames - which is why it is useful before the
stylist has labelled anything.

It also carries the one measurement that will be fed back into the code: the
median a*b* of the sclera across the set's **daylight** frames, uncorrected.
Under daylight the illuminant is close to D65, so what the sclera measures there
is close to the sclera's own colour - and that is what ``sclera_target_ab``
should be set to. Correcting to (0, 0) instead pushes every photo cool by the
difference.

The numbers are written next to the HTML as ``summary.json`` so two runs can be
compared directly.
"""

from __future__ import annotations

import statistics
from dataclasses import dataclass, field
from datetime import datetime, timezone
from typing import Any

import colour
import numpy as np

from ..fixtures import Label, Lighting, Resolution, group_by_person
from ..models import AnalysisResult, LabSample, Samples

# The condition every other one is compared against: daylight is the closest
# thing to a neutral illuminant a phone will see outside a studio.
REFERENCE_LIGHTING = Lighting.DAYLIGHT

TRACKED_ZONES = ("skin", "hair", "iris")


def delta_e(left: LabSample | None, right: LabSample | None) -> float | None:
    """CIEDE2000 between two samples, or None if either is missing."""
    if left is None or right is None:
        return None
    return round(
        float(
            colour.delta_E(
                np.asarray([left.L, left.a, left.b], dtype=np.float64),
                np.asarray([right.L, right.a, right.b], dtype=np.float64),
                method="CIE 2000",
            )
        ),
        3,
    )


@dataclass
class PhotoStability:
    filename: str
    lighting: str | None
    status: str
    reject_reason: str | None
    is_reference: bool
    method: str | None = None
    """Which reference white balance used on this photo: sclera, scene or none."""

    single_eye: bool = False
    correction_strength: float | None = None
    samples: dict[str, LabSample | None] = field(default_factory=dict)
    samples_before: dict[str, LabSample | None] = field(default_factory=dict)
    deltas: dict[str, float | None] = field(default_factory=dict)
    deltas_before: dict[str, float | None] = field(default_factory=dict)
    sclera_ab: tuple[float, float] | None = None
    """The sclera's a*b* as measured, before correction. Only meaningful on a
    daylight frame, where it is close to the sclera's own colour."""

    def as_dict(self) -> dict[str, Any]:
        return {
            "file": self.filename,
            "lighting": self.lighting,
            "status": self.status,
            "reject_reason": self.reject_reason,
            "is_reference": self.is_reference,
            "white_balance": {
                "method": self.method,
                "single_eye": self.single_eye,
                "correction_strength": self.correction_strength,
            },
            "samples": {zone: _lab(s) for zone, s in self.samples.items()},
            "samples_before": {zone: _lab(s) for zone, s in self.samples_before.items()},
            "delta_e": dict(self.deltas),
            "delta_e_before": dict(self.deltas_before),
            "sclera_ab_measured": list(self.sclera_ab) if self.sclera_ab else None,
        }


@dataclass
class PersonStability:
    person_id: str
    reference_file: str | None
    reference_kind: str
    """``daylight`` when that shot exists, ``median`` when the baseline is the
    per-person median instead, ``none`` when nothing could be measured."""

    photos: list[PhotoStability] = field(default_factory=list)

    def as_dict(self) -> dict[str, Any]:
        return {
            "person_id": self.person_id,
            "reference_file": self.reference_file,
            "reference_kind": self.reference_kind,
            "photos": [photo.as_dict() for photo in self.photos],
        }


def build_stability(
    labels: dict[str, Label],
    results: dict[str, AnalysisResult],
) -> list[PersonStability]:
    """Group results by person and measure each photo against that person's
    reference condition, before and after white balance."""
    people: list[PersonStability] = []

    for person_id, filenames in group_by_person(labels).items():
        usable = {
            name: results[name]
            for name in filenames
            if name in results and not results[name].rejected
        }
        reference_file = _pick_reference(filenames, labels, usable)
        after = {name: result.samples for name, result in usable.items()}
        before = {name: _uncorrected(result) for name, result in usable.items()}

        baseline_after = _baseline(after, reference_file)
        baseline_before = _baseline(before, reference_file)
        kind = "daylight" if reference_file else ("median" if baseline_after else "none")

        photos: list[PhotoStability] = []
        for name in filenames:
            result = results.get(name)
            label = labels[name]
            measured = _zones(result.samples if result else None)
            raw = _zones(_uncorrected(result) if result else None)
            sclera = None if result is None else _uncorrected(result).sclera
            photos.append(
                PhotoStability(
                    filename=name,
                    lighting=label.lighting.value if label.lighting else None,
                    status=result.status.value if result else "not analysed",
                    reject_reason=(
                        result.reject_reason.value if result and result.reject_reason else None
                    ),
                    is_reference=name == reference_file,
                    method=result.white_balance.method.value if result else None,
                    single_eye=bool(result and result.white_balance.single_eye),
                    correction_strength=(
                        result.white_balance.correction_strength if result else None
                    ),
                    samples=measured,
                    samples_before=raw,
                    deltas={
                        zone: delta_e(measured[zone], baseline_after.get(zone))
                        for zone in TRACKED_ZONES
                    },
                    deltas_before={
                        zone: delta_e(raw[zone], baseline_before.get(zone))
                        for zone in TRACKED_ZONES
                    },
                    sclera_ab=None if sclera is None else (sclera.a, sclera.b),
                )
            )
        people.append(PersonStability(person_id, reference_file, kind, photos))

    return people


def summarise(
    people: list[PersonStability],
    algo_version: str,
    labels: Resolution | None = None,
) -> dict[str, Any]:
    """The set-wide numbers, in the shape ``summary.json`` is written in."""
    photos = [photo for person in people for photo in person.photos]
    # The reference compares to itself at zero, which would flatter the median.
    comparable = [p for p in photos if not p.is_reference]

    by_lighting: dict[str, Any] = {}
    for lighting in {p.lighting for p in comparable if p.lighting}:
        same = [p for p in comparable if p.lighting == lighting]
        block = _before_after(same)
        if block is not None:
            by_lighting[str(lighting)] = block

    return {
        "algo_version": algo_version,
        "generated_at": datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "labels": _labels_block(labels),
        "white_balance": _white_balance_block(photos),
        "people": len(people),
        "photos": len(photos),
        "analysed": sum(1 for p in photos if p.status == "ok"),
        "skin_delta_e": _before_after(comparable),
        "by_lighting": by_lighting,
        "per_person": [person.as_dict() for person in people],
    }


# --- helpers ---------------------------------------------------------------


def _white_balance_block(photos: list[PhotoStability]) -> dict[str, Any]:
    """What the correction did across the set, and the one number that comes
    back into the code."""
    methods: dict[str, int] = {}
    for photo in photos:
        if photo.method:
            methods[photo.method] = methods.get(photo.method, 0) + 1
    strengths = [p.correction_strength for p in photos if p.correction_strength]
    return {
        "methods": methods,
        "single_eye": sum(1 for p in photos if p.single_eye),
        "color_cast": sum(1 for p in photos if p.reject_reason == "color_cast"),
        "correction_strength": _spread(strengths) if strengths else None,
        "sclera_daylight_ab": _sclera_target(photos),
    }


def _sclera_target(photos: list[PhotoStability]) -> dict[str, Any] | None:
    """Median a*b* of the sclera across the daylight frames, as measured.

    This is the calibration ``sclera_target_ab`` is waiting for. Daylight only,
    and uncorrected on purpose: under daylight the illuminant is close to D65,
    so what the sclera measures there is close to the sclera's own colour. Read
    off a corrected sample it would be circular - the correction puts it on the
    current target by construction.
    """
    values = [p.sclera_ab for p in photos if p.lighting == REFERENCE_LIGHTING.value and p.sclera_ab]
    if not values:
        return None
    return {
        "photos": len(values),
        "a": round(statistics.median(a for a, _ in values), 3),
        "b": round(statistics.median(b for _, b in values), 3),
    }


def _before_after(photos: list[PhotoStability]) -> dict[str, Any] | None:
    """Skin drift with and without the correction, side by side."""
    after = [d for d in (p.deltas.get("skin") for p in photos) if d is not None]
    before = [d for d in (p.deltas_before.get("skin") for p in photos) if d is not None]
    if not after and not before:
        return None
    return {
        "photos": len(after or before),
        "before": _spread(before) if before else None,
        "after": _spread(after) if after else None,
    }


def _spread(values: list[float]) -> dict[str, Any]:
    ordered = sorted(values)
    return {
        "photos": len(ordered),
        "median": round(statistics.median(ordered), 3),
        "p90": round(_percentile(ordered, 90.0), 3),
        "max": round(ordered[-1], 3),
    }


def _percentile(ordered: list[float], percentile: float) -> float:
    if len(ordered) == 1:
        return ordered[0]
    return float(np.percentile(np.asarray(ordered, dtype=np.float64), percentile))


def _labels_block(labels: Resolution | None) -> dict[str, Any]:
    """Where the grouping came from, and what did not line up.

    Carried into ``summary.json`` and the HTML rather than only printed on
    stderr: six months later the report is the only artifact left, and "which
    photos were not in the numbers" is the first question it has to answer.
    """
    if labels is None:
        return {"source": "none", "warnings": [], "skipped": []}
    return {
        "source": labels.source,
        "warnings": list(labels.warnings),
        "skipped": [{"file": name, "reason": reason} for name, reason in labels.skipped],
    }


def _pick_reference(
    filenames: list[str], labels: dict[str, Label], measured: dict[str, Any]
) -> str | None:
    """The person's daylight shot, if there is one that actually analysed."""
    for name in filenames:
        if labels[name].lighting is REFERENCE_LIGHTING and name in measured:
            return name
    return None


def _uncorrected(result: AnalysisResult | None) -> Samples:
    """What was measured before adaptation.

    Only the debug artifacts carry it - the contract carries the corrected
    samples - so a run without ``debug=True`` reports before and after as the
    same thing, which is the honest answer for a run that kept no before.
    """
    if result is None:
        return Samples()
    raw = getattr(result.debug, "uncorrected_samples", None)
    return raw if isinstance(raw, Samples) else result.samples


def _zones(samples: Samples | None) -> dict[str, LabSample | None]:
    if samples is None:
        return dict.fromkeys(TRACKED_ZONES)
    return {zone: getattr(samples, zone, None) for zone in TRACKED_ZONES}


def _lab(sample: LabSample | None) -> dict[str, float] | None:
    if sample is None:
        return None
    return {"L": sample.L, "a": sample.a, "b": sample.b, "C": sample.C, "h": sample.h}


def _baseline(
    measured: dict[str, Samples], reference_file: str | None
) -> dict[str, LabSample | None]:
    """The person's reference colours: their daylight shot, or their own median.

    The median says nothing about which reading is *correct* - only how far
    apart the conditions are, which is the question this report exists to
    answer.
    """
    if reference_file is not None and reference_file in measured:
        return _zones(measured[reference_file])

    baseline: dict[str, LabSample | None] = {}
    for zone in TRACKED_ZONES:
        found = [s for s in (getattr(v, zone, None) for v in measured.values()) if s is not None]
        if not found:
            baseline[zone] = None
            continue
        lightness = statistics.median(s.L for s in found)
        a = statistics.median(s.a for s in found)
        b = statistics.median(s.b for s in found)
        baseline[zone] = LabSample(
            L=round(lightness, 3),
            a=round(a, 3),
            b=round(b, 3),
            C=round(float(np.hypot(a, b)), 3),
            h=round(float(np.degrees(np.arctan2(b, a)) % 360.0), 3),
            pixels=sum(s.pixels for s in found),
            L_iqr=round(max(s.L_iqr for s in found), 3),
        )
    return baseline
