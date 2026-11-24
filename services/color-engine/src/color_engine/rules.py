"""Replay the sample-level rules over numbers that were measured earlier.

The question this answers: *given the thresholds as they stand today, what would
this face be judged as?* - asked of a profile measured weeks ago, without the
photograph, which is long deleted.

It is possible at all only because ``samples`` keeps a rejected zone's numbers
(see :class:`~color_engine.models.Samples`). It is worth doing because every
threshold in ``config.py`` is marked PRELIMINARY: the only honest way to move one
is to see what it would have done to the faces already collected, and re-shooting
them is not an option.

**What can be replayed, and what cannot.** A rule that is a function of the
stored numbers can be re-run exactly; a rule that read the pixels cannot be
re-run at all, because the pixels are gone. This module never guesses across that
line - it says which of the two each zone is in:

* Replayable: ``too_dark``, ``not_brighter_than_iris``, ``darker_than_skin``,
  ``more_chromatic_than_skin``, ``eyes_disagree``, and the "light and flat" half
  of ``overexposed``.
* Needs the photograph: ``eye_too_small``, ``too_few_pixels``, ``too_noisy``,
  and the clipped-fraction half of ``overexposed``.

The consequence is worth stating plainly: a zone this module calls usable may
have been rejected for something it cannot see. That is why every replay carries
``blind_spots``, and why this never claims to reproduce ``zones`` - it re-decides
the part of ``zones`` that is arithmetic.

Read-only and pure. It takes numbers and returns numbers, opens no file and
changes no stored result.
"""

from __future__ import annotations

import math
from dataclasses import dataclass
from typing import Any

from .config import EngineConfig
from .models import LabSample, Samples
from .sampling import CHROMATIC_ZONES, _under_skin_tolerance

REPLAYABLE_REASONS: frozenset[str] = frozenset(
    {
        "overexposed",
        "too_dark",
        "not_brighter_than_iris",
        "darker_than_skin",
        "more_chromatic_than_skin",
        "eyes_disagree",
    }
)
"""Reasons this module can re-decide from the stored numbers alone."""

PIXEL_REASONS: frozenset[str] = frozenset({"eye_too_small", "too_few_pixels", "too_noisy"})
"""Reasons decided on pixels. Nothing here can be revisited without the
photograph, and a replay says so rather than calling the zone usable."""

NO_USABLE_EYE = "no_usable_eye"
"""The pooled sclera's own answer when neither eye survived. Not a ScleraReason -
the engine reports the worst of the two eyes' reasons there, and repeating it on
the pool would read as a third failure rather than as the absence of input."""

CLIPPING_BLIND_SPOT = "clipped_fraction"
"""``overexposed`` has two signals and only one survives into the samples. The
"light and flat" half is arithmetic on L* and C*; the clipped-fraction half is a
count of pixels at the 8-bit ceiling, so a zone caught only by that one replays
as usable. Named, so the caller can say which."""

SPREAD_BLIND_SPOT = "ab_spread"
"""``too_noisy`` measures the width of a pixel cloud in a*b*. ``LabSample``
carries the median and the L* IQR, not that, so the check cannot be re-run."""


@dataclass(frozen=True)
class Check:
    """One rule, re-run: what it measured, what it allows, and by how much.

    ``margin`` is the headroom in the units of the check, positive when the zone
    passed. It is the number the whole exercise is for: "rejected" is not
    reviewable, while "rejected by 0.4" and "rejected by 18" are different
    problems with different answers.
    """

    name: str
    reason: str
    passed: bool
    value: float
    limit: float
    lower_bound: bool = False
    """True when the limit is a floor the value must reach (brighter than the
    iris), false when it is a ceiling the value must stay under (darker than the
    skin). Only the sign of the margin depends on it."""

    @property
    def margin(self) -> float:
        room = self.value - self.limit if self.lower_bound else self.limit - self.value
        return round(room, 3)

    def as_dict(self) -> dict[str, Any]:
        return {
            "name": self.name,
            "reason": self.reason,
            "passed": self.passed,
            "value": round(self.value, 3),
            "limit": round(self.limit, 3),
            "margin": self.margin,
        }


@dataclass(frozen=True)
class ZoneReplay:
    """What today's thresholds would say about one zone.

    ``usable`` is ``None`` when there was nothing to judge: the zone has no
    numbers, so whatever happened to it happened before any of these rules ran.
    """

    zone: str
    usable: bool | None
    reason: str | None
    decided_by: str | None
    checks: tuple[Check, ...] = ()
    blind_spots: tuple[str, ...] = ()

    @property
    def replayed(self) -> bool:
        return self.usable is not None

    def as_dict(self) -> dict[str, Any]:
        return {
            "zone": self.zone,
            "replayed": self.replayed,
            "usable": self.usable,
            "reason": self.reason,
            "decided_by": self.decided_by,
            "checks": [check.as_dict() for check in self.checks],
            "blind_spots": list(self.blind_spots),
        }


def replay_rules(samples: Samples, config: EngineConfig) -> dict[str, ZoneReplay]:
    """Re-decide every zone this module can, from the stored numbers.

    Each eye is judged against ``samples.skin`` as stored - the pooled figure
    the engine itself used - so a cheek that was excluded from it then is still
    excluded from it now. Recomputing the pool here would be answering a
    different question from the one the profile was measured under.
    """
    parts = samples.skin_parts
    replays: dict[str, ZoneReplay] = {}

    plain: list[tuple[str, LabSample | None]] = [
        ("skin", samples.skin),
        ("left_cheek", parts.left_cheek),
        ("right_cheek", parts.right_cheek),
        ("forehead", parts.forehead),
        ("hair", samples.hair),
        ("iris", samples.iris),
        ("iris_left", samples.iris_parts.left),
        ("iris_right", samples.iris_parts.right),
    ]
    for zone, sample in plain:
        replays[zone] = (
            _nothing(zone)
            if sample is None
            else _verdict(zone, _blown_check(sample, zone, config), (CLIPPING_BLIND_SPOT,))
        )

    for side in ("left", "right"):
        zone = f"sclera_{side}"
        sample = getattr(samples.sclera_parts, side)
        replays[zone] = (
            _nothing(zone)
            if sample is None
            else _verdict(
                zone,
                _sclera_checks(sample, samples.skin, getattr(samples.iris_parts, side), config),
                (SPREAD_BLIND_SPOT,),
            )
        )

    replays["sclera"] = _pooled_sclera(samples, replays, config)

    return replays


def _verdict(zone: str, checks: list[Check], blind_spots: tuple[str, ...]) -> ZoneReplay:
    """First failure wins, in the order the engine runs them - so the check
    named is the one that would decide it, not merely one that would object."""
    failed = next((check for check in checks if not check.passed), None)

    return ZoneReplay(
        zone=zone,
        usable=failed is None,
        reason=None if failed is None else failed.reason,
        decided_by=None if failed is None else failed.name,
        checks=tuple(checks),
        blind_spots=blind_spots,
    )


def _nothing(zone: str) -> ZoneReplay:
    return ZoneReplay(zone=zone, usable=None, reason=None, decided_by=None)


def _blown_check(sample: LabSample, zone: str, config: EngineConfig) -> list[Check]:
    """The half of ``overexposed`` that is arithmetic.

    Both conditions have to hold for the signal to fire - very light AND
    collapsed in chroma - so as a pass/fail it is one check. The value reported
    is the lightness, which is the half a reviewer moves a threshold on.
    """
    if zone not in CHROMATIC_ZONES:
        return []

    # Via locals: ruff reads the attributes `.L` and `.C` as constants and calls
    # the comparison a Yoda condition whichever way round it is written.
    lightness, chroma = sample.L, sample.C
    blown = lightness >= config.blown_lightness_min and chroma <= config.blown_chroma_max

    return [
        Check(
            name="light_and_flat",
            reason="overexposed",
            passed=not blown,
            value=lightness,
            limit=config.blown_lightness_min,
        )
    ]


def _sclera_checks(
    sclera: LabSample,
    skin: LabSample | None,
    iris: LabSample | None,
    config: EngineConfig,
) -> list[Check]:
    """The per-eye tests, in the order ``sampling._judged`` runs them."""
    lightness, chroma = sclera.L, sclera.C
    checks = [
        Check(
            name="above_sanity_floor",
            reason="too_dark",
            passed=lightness >= config.sclera_min_l_floor,
            value=lightness,
            limit=config.sclera_min_l_floor,
            lower_bound=True,
        )
    ]

    if iris is not None:
        over_iris = lightness - iris.L
        checks.append(
            Check(
                name="brighter_than_iris",
                reason="not_brighter_than_iris",
                passed=over_iris >= config.sclera_over_iris_min_l,
                value=over_iris,
                limit=config.sclera_over_iris_min_l,
                lower_bound=True,
            )
        )

    if skin is not None:
        skin_lightness, skin_chroma = skin.L, skin.C
        under_skin = skin_lightness - lightness
        # The loosened form only where the eye has an iris under it, exactly as
        # `_judged` decides it.
        tolerance = (
            _under_skin_tolerance(skin_lightness, config)
            if iris is not None
            else config.sclera_under_skin_max_l
        )
        chroma_limit = max(skin_chroma * config.sclera_chroma_ratio, config.sclera_chroma_floor)
        checks += [
            Check(
                name="not_darker_than_skin",
                reason="darker_than_skin",
                passed=under_skin <= tolerance,
                value=under_skin,
                limit=tolerance,
            ),
            Check(
                name="less_chromatic_than_skin",
                reason="more_chromatic_than_skin",
                passed=chroma <= chroma_limit,
                value=chroma,
                limit=chroma_limit,
            ),
        ]

    return checks


def _pooled_sclera(
    samples: Samples, replays: dict[str, ZoneReplay], config: EngineConfig
) -> ZoneReplay:
    """The pair test, over the eyes that passed their own checks just now.

    Over the REPLAYED survivors, not the eyes the original run kept: the point
    is what today's thresholds do end to end, and an eye today's per-eye tests
    admit is an eye today's pair test has to judge.
    """
    kept: list[LabSample] = []
    judged = 0
    for side in ("left", "right"):
        sample = getattr(samples.sclera_parts, side)
        if not replays[f"sclera_{side}"].replayed:
            continue
        judged += 1
        if sample is not None and replays[f"sclera_{side}"].usable:
            kept.append(sample)

    if judged == 0:
        # Neither eye had numbers, so there is nothing to pool and nothing to
        # say. Reporting "rejected" here would be a verdict about two eyes this
        # never looked at - which is exactly what a profile measured before the
        # samples were kept looks like.
        return _nothing("sclera")

    if not kept:
        return ZoneReplay(zone="sclera", usable=False, reason=NO_USABLE_EYE, decided_by=None)

    if len(kept) == 1:
        # One eye is a pool of one, which is what the engine does too.
        return ZoneReplay(zone="sclera", usable=True, reason=None, decided_by=None)

    left, right = kept
    distance = math.dist((left.a, left.b), (right.a, right.b))
    check = Check(
        name="eyes_agree",
        reason="eyes_disagree",
        passed=distance <= config.sclera_eye_agreement_max_dab,
        value=distance,
        limit=config.sclera_eye_agreement_max_dab,
    )

    return _verdict("sclera", [check], ())


__all__ = [
    "CLIPPING_BLIND_SPOT",
    "NO_USABLE_EYE",
    "PIXEL_REASONS",
    "REPLAYABLE_REASONS",
    "SPREAD_BLIND_SPOT",
    "Check",
    "ZoneReplay",
    "replay_rules",
]
