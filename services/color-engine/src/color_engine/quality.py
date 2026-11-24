"""The quality gate: reject reasons that need a measurement, not a detection.

``no_face`` and friends are decided where the face is looked for. What is left
here are the three reasons that only exist once colour has been measured.

``insufficient_skin``
    Skin is the one zone with no fallback. Hair can be dyed, eyes can be tiny,
    but with no skin measurement there is nothing to analyse.

``low_light``
    See :func:`low_light`. The hard part is not detecting darkness, it is
    telling a dark *frame* from dark *skin* - and getting that wrong means
    refusing a person's photo because of their complexion, which is not a bug
    we are willing to ship.

``uneven_light``
    See :func:`uneven_light`. Light from one side is not a white-balance
    problem: half the undertone being measured is a shadow, and no correction
    puts that back.

``color_cast``
    Decided in ``white_balance``, which is the only place that knows how far the
    light sat from D65. The gate only carries the verdict through.

``makeup_filter``
    **Deliberately not implemented, and not planned.** The value stays in the
    contract enum because Laravel maps it to copy and removing it would be a
    breaking change. There is no reliable way to detect a beauty filter or heavy
    foundation from a single frame: every heuristic that flags smoothed skin
    also flags good light, a soft lens and young skin, and the cost of a false
    positive is refusing a paying customer's photo for something she did not do.
    The defence at this stage is the instruction to the user and the question in
    the quiz, not a detector we cannot make honest.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field
from typing import Any

from .config import EngineConfig
from .models import RejectReason
from .sampling import Measurements
from .white_balance import WhiteBalanceResult
from .zones import SKIN_ZONES


@dataclass(frozen=True)
class QualityVerdict:
    """Why the photo was refused, or why it was let through with a caveat."""

    reject_reason: RejectReason | None = None
    confidence_factor: float = 1.0
    """A multiplier for the confidence stage 3 will compute. This stage can say
    'the photo is usable but I am less sure of it'; it cannot publish a number
    of its own."""

    notes: list[str] = field(default_factory=list)
    signals: dict[str, Any] = field(default_factory=dict)
    """The measurements the verdict was made on, for the report. A rejection the
    reviewer cannot audit is a rejection nobody can fix."""

    def as_dict(self) -> dict[str, Any]:
        return {
            "reject_reason": self.reject_reason.value if self.reject_reason else None,
            "confidence_factor": self.confidence_factor,
            "notes": list(self.notes),
            "signals": dict(self.signals),
        }


def quality_gate(
    measured: Measurements,
    balanced: WhiteBalanceResult,
    config: EngineConfig,
) -> QualityVerdict:
    """Decide whether the photo can carry an analysis.

    Takes the measurements *before* correction: exposure is a property of the
    photograph, and asking about it after the illuminant has been divided out
    would be asking the wrong question.
    """
    if measured.samples.skin is None:
        return QualityVerdict(reject_reason=RejectReason.INSUFFICIENT_SKIN)

    if balanced.reject_reason is not None:  # pragma: no cover - the engine gates earlier
        return QualityVerdict(reject_reason=balanced.reject_reason, notes=list(balanced.notes))

    # Exposure first, then the shape of the light. A frame that is too dark to
    # measure is too dark to judge the evenness of, so asking in the other order
    # would report a confident-sounding stop difference between two patches of
    # noise.
    exposure = low_light(measured, balanced, config)
    if exposure.reject_reason is not None:
        return exposure

    # Then the other end of the same axis. Asked after low_light because a frame
    # can only be one of the two, and before uneven light because a blown patch
    # IS a light difference - measuring evenness across it would report a
    # confident stop count between skin and a ceiling.
    blown = overexposure(measured, config)
    if blown.reject_reason is not None:
        return _combine(exposure, blown)

    return _combine(_combine(exposure, blown), uneven_light(measured, config))


def overexposure(measured: Measurements, config: EngineConfig) -> QualityVerdict:
    """Refuse a frame whose skin the camera stopped recording.

    A blown highlight is a missing measurement wearing the costume of a pale,
    neutral one, so it cannot be left to the reader to notice. One sub-zone gone
    is survivable - the other two carry the colour and the confidence pays for
    it. Two gone, or an aggregate that is itself light and flat, is not: at that
    point "skin colour" is the colour of the lamp.

    Counted over the three sub-zones rather than over ``skin``, because the
    aggregate is a median of the three and a single blown cheek does not always
    move it. That is precisely the case this has to catch: the number looks
    reasonable and a third of the evidence behind it is a ceiling.
    """
    blown = sorted(measured.overexposed & set(SKIN_ZONES))
    aggregate = "skin" in measured.overexposed
    signals = {
        "overexposed_zones": sorted(measured.overexposed),
        "clipped_fractions": measured.clipped_fractions,
    }

    if aggregate or len(blown) >= config.max_overexposed_skin_zones:
        return QualityVerdict(
            reject_reason=RejectReason.OVEREXPOSED,
            notes=[
                "skin aggregate is light with no chroma left"
                if aggregate
                else f"{len(blown)} of 3 skin zones overexposed: {', '.join(blown)}"
            ],
            signals=signals,
        )

    if blown:
        return QualityVerdict(
            confidence_factor=config.confidence_factor_overexposed,
            notes=[f"measured without {', '.join(blown)}: overexposed"],
            signals=signals,
        )

    return QualityVerdict(signals=signals)


def _combine(first: QualityVerdict, second: QualityVerdict) -> QualityVerdict:
    """Fold two clean verdicts into one.

    Confidence multiplies: two separate reasons to be less sure are two reasons,
    not the worse of the two. Signals and notes accumulate so the report shows
    everything that was looked at, not only what fired.
    """
    return QualityVerdict(
        reject_reason=second.reject_reason,
        confidence_factor=round(first.confidence_factor * second.confidence_factor, 4),
        notes=first.notes + second.notes,
        signals=first.signals | second.signals,
    )


def relative_luminance(lightness: float) -> float:
    """CIE L* back to relative luminance Y, 0..1.

    The inverse of the CIELAB lightness function, including its linear segment
    near black, so a dark patch is not crushed to zero and made to look like an
    infinite ratio against its neighbour.
    """
    fy = (lightness + 16.0) / 116.0
    return fy**3 if fy > 6 / 29 else 3 * (6 / 29) ** 2 * (fy - 4 / 29)


def stops_between(left: float, right: float) -> float:
    """How many doublings of light separate two measured lightnesses.

    A ratio of luminances, which is a property of the LIGHT: it cancels the
    reflectance of whatever the light landed on, so the same number means the
    same thing on any skin. See the config block for why L* would not.
    """
    a, b = relative_luminance(left), relative_luminance(right)
    floor = 1e-6  # a patch this dark carries no usable ratio anyway
    return abs(math.log2(max(a, floor) / max(b, floor)))


def uneven_light(measured: Measurements, config: EngineConfig) -> QualityVerdict:
    """Is the light falling from one side?

    Two questions, because the two pairs mean different things. Cheek against
    cheek is the one that matters: a face is near enough symmetrical, so a
    difference between them is a difference in the light, not in the person.
    Forehead against the cheeks is looser - the forehead is the most exposed
    plane of a face and reads brighter under light nobody would call uneven.

    A side-lit photo is not a white-balance problem. Half the undertone being
    measured is a shadow, and no correction puts that back.
    """
    parts = measured.samples.skin_parts
    left, right, forehead = parts.left_cheek, parts.right_cheek, parts.forehead

    cheeks = stops_between(left.L, right.L) if left and right else None

    brow = None
    lit_cheeks = [p.L for p in (left, right) if p is not None]
    if forehead is not None and lit_cheeks:
        brow = stops_between(forehead.L, sum(lit_cheeks) / len(lit_cheeks))

    signals: dict[str, Any] = {
        "cheek_balance_stops": None if cheeks is None else round(cheeks, 3),
        "forehead_offset_stops": None if brow is None else round(brow, 3),
    }

    refused = (cheeks is not None and cheeks > config.uneven_light_reject_stops) or (
        brow is not None and brow > config.uneven_light_forehead_reject_stops
    )
    if refused:
        return QualityVerdict(
            reject_reason=RejectReason.UNEVEN_LIGHT,
            notes=[_describe(cheeks, brow, "too far apart to measure an undertone from")],
            signals=signals,
        )

    warned = (cheeks is not None and cheeks > config.uneven_light_warn_stops) or (
        brow is not None and brow > config.uneven_light_forehead_warn_stops
    )
    if warned:
        return QualityVerdict(
            confidence_factor=config.confidence_factor_uneven_light,
            notes=[_describe(cheeks, brow, "uneven enough to trust the reading less")],
            signals=signals,
        )

    return QualityVerdict(signals=signals)


def _describe(cheeks: float | None, brow: float | None, verdict: str) -> str:
    measured = []
    if cheeks is not None:
        measured.append(f"{cheeks:.1f} stops between the cheeks")
    if brow is not None:
        measured.append(f"{brow:.1f} between forehead and cheeks")

    return "the light is " + verdict + " (" + ", ".join(measured) + ")"


def low_light(
    measured: Measurements,
    balanced: WhiteBalanceResult,
    config: EngineConfig,
) -> QualityVerdict:
    """Is this a dark photograph, or a photograph of a dark-skinned person?

    A dark median skin L* on its own answers neither question. Deep skin under
    good light measures exactly like light skin in the dark, and rejecting the
    first as ``low_light`` would refuse someone's photo because of their
    complexion. So darkness in the skin only *raises* the question, and the
    answer comes from the rest of the frame:

    * the **sclera**, whose reflectance is roughly the same for everybody. A
      bright sclera means the light was there, whatever the skin measures;
    * the **scene** behind the person, which is unrelated to the person.

    If neither is available there is nothing to tell the two apart with, and the
    photo is let through with the confidence knocked down rather than refused.
    Refusing on a signal we cannot interpret is the one outcome that is
    definitely wrong.
    """
    skin = measured.samples.skin
    assert skin is not None  # narrowed by the caller

    shadow = measured.skin_shadow_fraction
    spread = _worst_cheek_spread(measured)
    sclera_lightness = _brightest_sclera(measured)
    scene_lightness = balanced.scene.median_L if balanced.scene is not None else None

    signals: dict[str, Any] = {
        "skin_L": skin.L,
        "skin_shadow_fraction": shadow,
        "cheek_L_iqr": spread,
        "sclera_L": sclera_lightness,
        "scene_L": scene_lightness,
    }

    skin_lightness = skin.L
    triggers = []
    if skin_lightness < config.low_light_skin_l:
        triggers.append("skin median is dark")
    if shadow is not None and shadow >= config.low_light_max_shadow_fraction:
        triggers.append(f"{shadow:.0%} of the skin sits in shadow")
    if spread is not None and spread > config.low_light_max_skin_l_iqr:
        triggers.append("the cheeks are noisy")
    if not triggers:
        return QualityVerdict(signals=signals)

    if sclera_lightness is not None and sclera_lightness >= config.low_light_sclera_l_ok:
        return QualityVerdict(
            notes=[
                f"dark skin ({', '.join(triggers)}) but the sclera is at L* "
                f"{sclera_lightness:.0f} - the light was there"
            ],
            signals=signals,
        )
    if scene_lightness is not None and scene_lightness >= config.low_light_scene_l_ok:
        return QualityVerdict(
            notes=[
                f"dark skin ({', '.join(triggers)}) but the scene is at L* "
                f"{scene_lightness:.0f} - the frame is exposed"
            ],
            signals=signals,
        )
    if sclera_lightness is None and scene_lightness is None:
        return QualityVerdict(
            confidence_factor=config.confidence_factor_exposure_unknown,
            notes=[
                f"dark skin ({', '.join(triggers)}) and nothing in the frame to tell a dark "
                "photo from dark skin - not refused, but trusted less"
            ],
            signals=signals,
        )

    return QualityVerdict(
        reject_reason=RejectReason.LOW_LIGHT,
        notes=[f"{', '.join(triggers)}, and the rest of the frame is dark too"],
        signals=signals,
    )


def _worst_cheek_spread(measured: Measurements) -> float | None:
    """The widest L* IQR across the cheeks.

    The cheeks, not the forehead: the forehead polygon is large enough to
    straddle a real lighting gradient on a perfectly good photo, so its spread
    says as much about the shape of a face as about the noise in it.
    """
    parts = measured.samples.skin_parts
    spreads = [p.L_iqr for p in (parts.left_cheek, parts.right_cheek) if p is not None]
    return max(spreads) if spreads else None


def _brightest_sclera(measured: Measurements) -> float | None:
    """The brighter eye's median L*, from whatever was measured.

    Deliberately not restricted to a sclera good enough to white-balance with: a
    mask that is too noisy to carry a chromaticity still says how much light
    reached the eye, which is all this needs.
    """
    values = [
        side.quality.median_L for side in measured.sclera_sides if side.quality.median_L is not None
    ]
    return max(values) if values else None
