"""Estimating the light, and taking it out of the measurement.

Without this step a warm indoor bulb reads as warm skin and every photo drifts
autumn. The step answers one question - *what colour was the light* - and then
adapts every sample to D65 so that two photos of the same face under different
lamps can be compared at all.

Where the estimate comes from, in order:

1. **The sclera.** The one near-neutral surface a face reliably carries. Two
   eyes see the same light, so the two measurements agreeing is strong evidence
   that both masks really are on sclera and that the colour they share is the
   light's.
2. **The scene behind the person.** Shades of grey over the segmenter's
   background class. Weaker, and ambiguous in a way the sclera is not - see
   ``estimate_from_scene``.
3. **Nothing.** Report the samples as measured and say so.

**Skin is never a reference, in any form.** Correcting the light against skin
would absorb the undertone the whole analysis exists to measure: the estimate
would eat the signal. Skin appears here only as the thing the sclera has to be
brighter than, and as one voice in the "is this frame visibly cast" check that
runs when there is no estimate at all.

Clothes are excluded from the scene for the same kind of reason: one red dress
fills half the frame and drags a grey-world estimate to cyan.

The correction is applied to the median XYZ of each sample, not to the image.
Adapting six values is the same von Kries transform as adapting several million
pixels and costs nothing. The pixels are only touched for the report's
before/after preview, and only when ``debug=True``.

CAT16 is the transform, not CAT02: it is the CIE's own correction of CAT02
(Li et al. 2017, carried into CIECAM16), and the thing it fixes - negative cone
responses and the instability that follows for saturated colours and extreme
illuminants - is exactly the regime we hand it, since the photos that need
white balance most are the ones shot under a tungsten bulb.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field
from itertools import pairwise
from typing import Any

import colour
import numpy as np
from colour.adaptation import CHROMATIC_ADAPTATION_TRANSFORMS

from .arrays import FloatArray, ImageArray
from .config import EngineConfig
from .models import (
    EyeParts,
    LabSample,
    RejectReason,
    Samples,
    SkinParts,
    WhiteBalance,
    WhiteBalanceMethod,
    WhiteBalanceReason,
)
from .sampling import ScleraReason, ScleraSide, rgb_to_lab, worst_reason
from .zones import Zones

TRANSFORM = "CAT16"
CAT16 = np.asarray(CHROMATIC_ADAPTATION_TRANSFORMS[TRANSFORM], dtype=np.float64)
CAT16_INVERSE = np.linalg.inv(CAT16)

D65_XY: FloatArray = np.asarray(
    colour.CCS_ILLUMINANTS["CIE 1931 2 Degree Standard Observer"]["D65"], dtype=np.float64
)
D65_XYZ: FloatArray = np.asarray(colour.xy_to_XYZ(D65_XY), dtype=np.float64)
D65_UV: FloatArray = np.asarray(colour.xy_to_Luv_uv(D65_XY), dtype=np.float64)

# Outside this range a correlated colour temperature is a number, not a fact:
# the estimate has wandered too far from the Planckian locus for CCT to describe
# it. The strength in u'v' still does, which is why that is what the gate uses.
CCT_PLAUSIBLE = (1000.0, 25000.0)

SRGB_TO_XYZ: FloatArray = np.asarray(
    colour.RGB_COLOURSPACES["sRGB"].matrix_RGB_to_XYZ, dtype=np.float64
)

CAST_SUSPECTED = "cast_suspected"
"""Every zone in the frame is strongly and consistently coloured, and there is
no illuminant estimate to check it against. A suspicion, reported in debug and
worth a lower confidence - never a refusal."""


# --- results ---------------------------------------------------------------


@dataclass(frozen=True)
class ScleraEstimate:
    """Whether the sclera can be used as the white reference, and why not."""

    sample: LabSample | None
    reason: ScleraReason
    single_eye: bool = False
    eye_distance: float | None = None
    """a*b* distance between the two eyes, when both were measured."""

    @property
    def usable(self) -> bool:
        return self.sample is not None and self.reason is ScleraReason.OK

    def as_dict(self) -> dict[str, Any]:
        return {
            "reason": self.reason.value,
            "single_eye": self.single_eye,
            "eye_distance": self.eye_distance,
            "usable": self.usable,
        }


@dataclass(frozen=True)
class SceneEstimate:
    """The grey-world fallback, and the reasons not to believe it."""

    pixels: int
    reason: str
    white_xyz: FloatArray | None = None
    median_L: float | None = None
    median_ab: tuple[float, float] | None = None
    ab_spread: float | None = None

    @property
    def usable(self) -> bool:
        return self.white_xyz is not None and self.reason == "ok"

    def as_dict(self) -> dict[str, Any]:
        return {
            "pixels": self.pixels,
            "reason": self.reason,
            "usable": self.usable,
            "median_L": self.median_L,
            "median_ab": list(self.median_ab) if self.median_ab else None,
            "ab_spread": self.ab_spread,
        }


@dataclass(frozen=True)
class WhiteBalanceResult:
    """What the step decided, for the pipeline and for the report."""

    samples: Samples
    """The samples the rest of the pipeline should use - adapted to D65 when a
    correction was applied, unchanged otherwise."""

    info: WhiteBalance
    """The contract object."""

    uncorrected: Samples
    """What was measured, before adaptation. Debug only - never serialised."""

    reject_reason: RejectReason | None = None
    """``color_cast`` when the light is too far off to correct honestly. Only
    ever set from a *measured* illuminant, never from a suspicion."""

    suspicion: str | None = None
    """``cast_suspected`` when the consensus heuristic fired. Debug only: it
    lowers the confidence and says why, and it cannot refuse a photo."""

    illuminant_xy: tuple[float, float] | None = None
    white_xyz: FloatArray | None = None
    sclera: ScleraEstimate | None = None
    scene: SceneEstimate | None = None
    confidence_factor: float = 1.0
    """A multiplier for the confidence stage 3 will compute. Debug only for now:
    this stage has no business publishing a confidence of its own."""

    notes: list[str] = field(default_factory=list)

    @property
    def applied(self) -> bool:
        return self.info.method is not WhiteBalanceMethod.NONE

    def as_dict(self) -> dict[str, Any]:
        return {
            "method": self.info.method.value,
            "single_eye": self.info.single_eye,
            "correction_strength": self.info.correction_strength,
            "estimated_cct": self.info.estimated_cct,
            "illuminant_xy": list(self.illuminant_xy) if self.illuminant_xy else None,
            "reject_reason": self.reject_reason.value if self.reject_reason else None,
            "suspicion": self.suspicion,
            "confidence_factor": self.confidence_factor,
            "sclera": self.sclera.as_dict() if self.sclera else None,
            "scene": self.scene.as_dict() if self.scene else None,
            "notes": list(self.notes),
        }


# --- the step ---------------------------------------------------------------


def apply_white_balance(
    rgb: ImageArray,
    zones: Zones,
    samples: Samples,
    sclera_sides: tuple[ScleraSide, ...],
    config: EngineConfig,
) -> WhiteBalanceResult:
    """Estimate the illuminant, adapt the samples to D65, or refuse to."""
    notes: list[str] = []

    sclera = assess_sclera(sclera_sides, config)
    # Measured even when the sclera wins. It is the fallback estimate, but it is
    # also how the quality gate tells a dark photo from dark skin, and having
    # both readings side by side in the report is how the two get calibrated
    # against each other.
    scene = estimate_from_scene(rgb, zones, config)

    if not config.white_balance_enabled:
        notes.append("white balance is switched off; samples reported exactly as measured")
        return WhiteBalanceResult(
            samples=samples,
            info=white_balance_info(config, single_eye=sclera.single_eye),
            uncorrected=samples,
            sclera=sclera,
            scene=scene,
            confidence_factor=config.confidence_factor_no_estimate,
            notes=notes,
        )

    white: FloatArray | None = None
    method = WhiteBalanceMethod.NONE
    why: WhiteBalanceReason | None = None
    if sclera.usable and sclera.sample is not None:
        white = illuminant_from_sclera(sclera.sample, config)
        method = WhiteBalanceMethod.SCLERA
    else:
        notes.append(f"sclera unusable: {sclera.reason.value}")
        why = WhiteBalanceReason.NO_SCLERA
        if scene.usable and scene.white_xyz is not None:
            white = scene.white_xyz
            method = WhiteBalanceMethod.SCENE
        else:
            notes.append(f"scene unusable: {scene.reason}")
            why = WhiteBalanceReason.NO_SCENE

    if white is None:
        return _uncorrected(samples, sclera, scene, config, notes, why)

    xy = tuple(float(v) for v in colour.XYZ_to_xy(white))
    strength = round(_distance_from_d65(white), 5)
    if strength > config.max_correction_duv:
        notes.append(
            f"illuminant sits {strength:.4f} from D65 in u'v', past "
            f"{config.max_correction_duv:.4f} - too far to correct honestly"
        )
        return WhiteBalanceResult(
            samples=samples,
            info=white_balance_info(
                config,
                single_eye=sclera.single_eye,
                correction_strength=strength,
                estimated_cct=_cct(xy),
            ),
            uncorrected=samples,
            reject_reason=RejectReason.COLOR_CAST,
            illuminant_xy=(xy[0], xy[1]),
            white_xyz=white,
            sclera=sclera,
            scene=scene,
            confidence_factor=1.0,
            notes=notes,
        )

    corrected = adapt_samples(samples, white)
    factor = 1.0
    if method is WhiteBalanceMethod.SCENE:
        factor = config.confidence_factor_scene
    elif sclera.single_eye:
        factor = config.confidence_factor_single_eye

    return WhiteBalanceResult(
        samples=corrected,
        info=white_balance_info(
            config,
            method=method,
            single_eye=sclera.single_eye,
            correction_strength=strength,
            estimated_cct=_cct(xy),
        ),
        uncorrected=samples,
        illuminant_xy=(xy[0], xy[1]),
        white_xyz=white,
        sclera=sclera,
        scene=scene,
        confidence_factor=factor,
        notes=notes,
    )


def _uncorrected(
    samples: Samples,
    sclera: ScleraEstimate,
    scene: SceneEstimate | None,
    config: EngineConfig,
    notes: list[str],
    why: WhiteBalanceReason | None = None,
) -> WhiteBalanceResult:
    """No estimate. The samples are handed back as measured, with the confidence
    knocked down - further if the frame *looks* cast, but never refused on that.

    A suspicion is not a measurement. The consensus heuristic below reads a
    warm-toned person in front of a beige wall exactly as it reads a warm room,
    so refusing on it would turn away warm colour types systematically - the
    opposite of what this product is for. ``color_cast`` stays a refusal only
    where there is an actual illuminant estimate and it is too far off to undo.
    """
    cast = _looks_cast(samples, scene, config)
    if cast is not None:
        notes.append(cast)
        return WhiteBalanceResult(
            samples=samples,
            info=white_balance_info(
                config,
                single_eye=sclera.single_eye,
                reason=WhiteBalanceReason.CAST_SUSPECTED,
            ),
            uncorrected=samples,
            suspicion=CAST_SUSPECTED,
            sclera=sclera,
            scene=scene,
            confidence_factor=config.confidence_factor_cast_suspected,
            notes=notes,
        )

    notes.append("no reliable illuminant estimate; samples reported as measured")
    return WhiteBalanceResult(
        samples=samples,
        info=white_balance_info(config, single_eye=sclera.single_eye, reason=why),
        uncorrected=samples,
        sclera=sclera,
        scene=scene,
        confidence_factor=config.confidence_factor_no_estimate,
        notes=notes,
    )


# --- the sclera -------------------------------------------------------------


def assess_sclera(
    sides: tuple[ScleraSide, ...],
    config: EngineConfig,
) -> ScleraEstimate:
    """Decide whether the sclera can carry the white reference.

    Every per-eye test has already run in ``sampling`` - enough pixels, tight
    enough in a*b* to be one surface, above the sanity floor, and brighter than
    the skin of the same face - and each is relative, because the absolute ones
    measure the wrong thing: lightness tracks exposure, not the colour of the
    light. So one bad mask does not take a good eye down with it: the survivor
    is used and the estimate is flagged ``single_eye``.

    What is left here is the one test that needs *both* eyes, and it is
    deliberately unforgiving. Both eyes see the same light; if two masks that
    each look fine on their own disagree about its colour, one of them is not on
    sclera, and nothing in a single frame says which. Both are dropped.
    """
    usable = [side for side in sides if side.usable]
    if not usable:
        return ScleraEstimate(sample=None, reason=worst_reason([side.quality for side in sides]))

    if len(usable) == 1:
        return ScleraEstimate(
            sample=usable[0].sample, reason=ScleraReason.OK, single_eye=True, eye_distance=None
        )

    left, right = usable[0].sample, usable[1].sample
    assert left is not None and right is not None  # narrowed by the loop above
    distance = round(math.dist((left.a, left.b), (right.a, right.b)), 3)
    if distance > config.sclera_eye_agreement_max_dab:
        return ScleraEstimate(sample=None, reason=ScleraReason.EYES_DISAGREE, eye_distance=distance)

    return ScleraEstimate(
        sample=_pool([side.sample for side in usable]),
        reason=ScleraReason.OK,
        eye_distance=distance,
    )


def illuminant_from_sclera(sclera: LabSample, config: EngineConfig) -> FloatArray:
    """The illuminant's XYZ, from a sclera measured under it.

    Von Kries in one line: if a surface whose colour under D65 would be
    ``target`` measures ``sclera`` instead, then the cone responses have been
    scaled by their ratio, and the light doing the scaling is D65's white point
    scaled the same way.

    ``target`` is not white. A healthy sclera is slightly warm, and correcting
    it to exact neutral would push every photo cool by that amount - a
    systematic error in the undertone, which is the one axis this product is
    sold on. See ``sclera_target_ab``.

    Lightness is carried through unchanged: the target is built at the sclera's
    own L*, so the ratio in Y is 1 and the transform moves chromaticity only.
    Exposure is not this step's business.
    """
    measured = np.asarray(colour.Lab_to_XYZ([sclera.L, sclera.a, sclera.b]), dtype=np.float64)
    target = np.asarray(colour.Lab_to_XYZ([sclera.L, *config.sclera_target_ab]), dtype=np.float64)

    lms_measured = CAT16 @ measured
    lms_target = CAT16 @ target
    if not np.all(np.abs(lms_target) > 1e-9):  # pragma: no cover - a black sclera is not one
        return D65_XYZ.copy()

    lms_white = (CAT16 @ D65_XYZ) * (lms_measured / lms_target)
    return _normalise(CAT16_INVERSE @ lms_white)


# --- the scene --------------------------------------------------------------


def estimate_from_scene(rgb: ImageArray, zones: Zones, config: EngineConfig) -> SceneEstimate:
    """Shades of grey over the background class.

    Only the background. Clothes are excluded on purpose - one saturated dress
    fills half the frame and drags the estimate to its complement - and so are
    hair, face and body skin, which are not grey by any stretch.

    Two things make the estimate unusable:

    * **too few pixels**, once clipped and shadowed ones are dropped. A blown
      window is a picture of the sensor's ceiling, not of the light, and a dark
      corner is a picture of the noise floor;
    * **one flat coloured surface**. A plain wall gives a confident estimate of
      *the wall's* colour, and from a single frame there is no way to tell a
      white wall under an orange lamp from an orange wall under a white one. A
      plain *neutral* wall is the good case and is kept: it is only the
      combination of low spread and high chroma that is ambiguous.
    """
    zone = zones.get("background")
    if zone is None:
        return SceneEstimate(pixels=0, reason="too_few_pixels")

    ys, xs = np.nonzero(zone.mask)
    if ys.size == 0:  # pragma: no cover - a zone with no pixels is not usable
        return SceneEstimate(pixels=0, reason="too_few_pixels")
    if ys.size > config.max_pixels_per_zone:
        rng = np.random.default_rng(config.sampling_seed)
        picked = rng.choice(ys.size, size=config.max_pixels_per_zone, replace=False)
        ys, xs = ys[picked], xs[picked]

    srgb = np.asarray(rgb[ys, xs], dtype=np.float64)
    lab = rgb_to_lab(srgb)
    keep = (srgb.max(axis=1) < config.scene_clip_level) & (lab[:, 0] >= config.scene_min_l)
    kept = int(keep.sum())
    if kept < config.scene_min_pixels:
        return SceneEstimate(pixels=kept, reason="too_few_pixels")

    lab = lab[keep]
    median_ab = np.median(lab[:, 1:], axis=0)
    distances = np.linalg.norm(lab[:, 1:] - median_ab, axis=1)
    spread = float(np.percentile(distances, config.scene_spread_percentile))
    chroma = float(np.hypot(*median_ab))
    stats: dict[str, Any] = {
        "pixels": kept,
        "median_L": round(float(np.median(lab[:, 0])), 3),
        "median_ab": (round(float(median_ab[0]), 3), round(float(median_ab[1]), 3)),
        "ab_spread": round(spread, 3),
    }
    if spread < config.scene_uniform_ab_spread and chroma > config.scene_uniform_max_chroma:
        return SceneEstimate(reason="uniform_coloured_surface", **stats)

    linear = np.asarray(colour.cctf_decoding(srgb[keep] / 255.0, function="sRGB"), dtype=np.float64)
    power = config.scene_minkowski_p
    estimate = np.power(np.mean(np.power(np.maximum(linear, 0.0), power), axis=0), 1.0 / power)
    if not np.all(estimate > 1e-9):  # pragma: no cover - a pure black scene is already dropped
        return SceneEstimate(reason="too_few_pixels", **stats)

    return SceneEstimate(reason="ok", white_xyz=_normalise(SRGB_TO_XYZ @ estimate), **stats)


# --- applying it ------------------------------------------------------------


def adapt_samples(samples: Samples, white_xyz: FloatArray) -> Samples:
    """Adapt every measured sample from the estimated illuminant to D65.

    EVERY sample, the per-side ones included. Rebuilding ``Samples`` from a
    hand-written list of fields quietly dropped ``iris_parts`` and
    ``sclera_parts``: a corrected photo reached the contract with the pooled
    numbers intact and each eye's own gone, and nothing said so.
    """

    def convert(sample: LabSample | None) -> LabSample | None:
        return None if sample is None else adapt_sample(sample, white_xyz)

    def convert_eyes(parts: EyeParts) -> EyeParts:
        return EyeParts(left=convert(parts.left), right=convert(parts.right))

    parts = samples.skin_parts
    return Samples(
        skin=convert(samples.skin),
        skin_parts=SkinParts(
            left_cheek=convert(parts.left_cheek),
            right_cheek=convert(parts.right_cheek),
            forehead=convert(parts.forehead),
        ),
        hair=convert(samples.hair),
        iris=convert(samples.iris),
        iris_parts=convert_eyes(samples.iris_parts),
        sclera=convert(samples.sclera),
        sclera_parts=convert_eyes(samples.sclera_parts),
    )


def adapt_sample(sample: LabSample, white_xyz: FloatArray) -> LabSample:
    """One sample, adapted. Pixel count and L* spread are carried through: they
    describe how the sample was gathered, and adaptation does not change that."""
    xyz = np.asarray(colour.Lab_to_XYZ([sample.L, sample.a, sample.b]), dtype=np.float64)
    adapted = colour.adaptation.chromatic_adaptation_VonKries(
        xyz, white_xyz, D65_XYZ, transform=TRANSFORM
    )
    lightness, a, b = (float(v) for v in colour.XYZ_to_Lab(adapted))
    return LabSample(
        L=round(lightness, 3),
        a=round(a, 3),
        b=round(b, 3),
        C=round(math.hypot(a, b), 3),
        h=round(math.degrees(math.atan2(b, a)) % 360.0, 3),
        pixels=sample.pixels,
        L_iqr=sample.L_iqr,
    )


def adapt_image(rgb: ImageArray, white_xyz: FloatArray) -> ImageArray:
    """The whole frame, adapted. **Preview only.**

    Nothing downstream measures this: the samples were adapted individually and
    are the answer. This exists so the report can show a before and an after
    side by side, which is the only way a human can judge whether the estimate
    was sane.
    """
    linear = np.asarray(colour.cctf_decoding(rgb / 255.0, function="sRGB"), dtype=np.float64)
    xyz = linear @ SRGB_TO_XYZ.T
    adapted = colour.adaptation.chromatic_adaptation_VonKries(
        xyz, white_xyz, D65_XYZ, transform=TRANSFORM
    )
    out = colour.XYZ_to_sRGB(adapted)
    return np.asarray(np.clip(out, 0.0, 1.0) * 255.0, dtype=np.uint8)


# --- helpers ----------------------------------------------------------------


def white_balance_info(
    config: EngineConfig,
    method: WhiteBalanceMethod = WhiteBalanceMethod.NONE,
    single_eye: bool = False,
    correction_strength: float = 0.0,
    estimated_cct: float | None = None,
    reason: WhiteBalanceReason | None = None,
) -> WhiteBalance:
    """The contract object, with the calibration flag filled in from config.

    The single place ``target_calibrated`` is decided, and the engine calls it
    for rejections too. It is a property of the configuration, not of the
    photograph: a consumer reading it off a photo rejected at ``load`` has to
    get the same answer as off one that was measured, or the field means nothing.
    """
    enabled = config.white_balance_enabled

    return WhiteBalance(
        enabled=enabled,
        # A correction that happened needs no excuse. One that did not always
        # has one, and "switched off" is the default because that is the only
        # reason that does not depend on the photograph.
        reason=(
            None
            if method is not WhiteBalanceMethod.NONE
            else (reason if enabled else WhiteBalanceReason.DISABLED)
        ),
        method=method,
        single_eye=single_eye,
        correction_strength=correction_strength,
        estimated_cct=estimated_cct,
        target_calibrated=config.sclera_target_calibrated,
    )


def _distance_from_d65(white_xyz: FloatArray) -> float:
    """How strong a correction this illuminant asks for.

    Euclidean distance in CIE 1976 u'v', not a temperature difference. CCT
    collapses the green-magenta axis - a fluorescent tube and a daylight lamp
    can share a CCT and look nothing alike - and kelvin are not perceptually
    even, so "500 K" means one thing at 3000 K and another at 8000 K. u'v' is
    near-uniform, is a plain distance in both directions at once, and is what
    LED binning is specified in. For scale: D75 sits 0.011 from D65, D50 0.023,
    a fluorescent tube around 0.04, a tungsten bulb 0.081.
    """
    uv = np.asarray(colour.xy_to_Luv_uv(colour.XYZ_to_xy(white_xyz)), dtype=np.float64)
    return float(np.hypot(*(uv - D65_UV)))


def _cct(xy: tuple[float, ...]) -> float | None:
    """CCT in kelvin, or None when the estimate is too far off the locus to
    describe with one."""
    try:
        kelvin = float(colour.xy_to_CCT(np.asarray(xy, dtype=np.float64), method="McCamy 1992"))
    except (ValueError, ZeroDivisionError):  # pragma: no cover - defensive
        return None
    if not math.isfinite(kelvin) or not CCT_PLAUSIBLE[0] <= kelvin <= CCT_PLAUSIBLE[1]:
        return None
    return round(kelvin, 1)


def _normalise(xyz: FloatArray) -> FloatArray:
    """Scale an illuminant XYZ to Y = 1, which is what the adaptation expects."""
    y = float(xyz[1])
    if abs(y) < 1e-9:  # pragma: no cover - defensive
        return D65_XYZ.copy()
    return np.asarray(xyz / y, dtype=np.float64)


def _pool(samples: list[LabSample | None]) -> LabSample:
    """Combine the eyes, weighting each equally. Two eyes, not two areas: a
    three-quarter-on head shows more of one sclera than the other, and that is
    a fact about the pose, not about the light."""
    present = [s for s in samples if s is not None]
    lightness = float(np.mean([s.L for s in present]))
    a = float(np.mean([s.a for s in present]))
    b = float(np.mean([s.b for s in present]))
    return LabSample(
        L=round(lightness, 3),
        a=round(a, 3),
        b=round(b, 3),
        C=round(math.hypot(a, b), 3),
        h=round(math.degrees(math.atan2(b, a)) % 360.0, 3),
        pixels=sum(s.pixels for s in present),
        L_iqr=round(max(s.L_iqr for s in present), 3),
    )


def _looks_cast(samples: Samples, scene: SceneEstimate | None, config: EngineConfig) -> str | None:
    """Does the frame *look* cast, with no estimate to prove it?

    The rule is consensus, and it is deliberately hard to trigger. Take every
    zone that has a colour - the sclera as measured, the skin, the background -
    and ask whether *even the least chromatic of them* is strongly coloured and
    they all point the same way. One coloured thing in a frame is a coloured
    thing; everything in the frame agreeing on a direction is a light.

    Skin is a voice here, never a reference: it cannot move the estimate,
    only corroborate what the others already say.

    This is the weakest test in the module, and it cannot refuse a photo. A
    warm-toned person in front of a beige wall reads exactly like a warm room,
    so a refusal here would fall on warm colour types systematically - a bias
    baked into the product, not a quality gate. All it does is lower the
    confidence and record the reasoning.
    """
    voices: list[tuple[str, float, float]] = []
    for name, sample in (("sclera", samples.sclera), ("skin", samples.skin)):
        if sample is not None:
            voices.append((name, sample.C, sample.h))
    if scene is not None and scene.median_ab is not None:
        a, b = scene.median_ab
        voices.append(("scene", math.hypot(a, b), math.degrees(math.atan2(b, a)) % 360.0))

    if len(voices) < 2:
        return None
    weakest = min(chroma for _, chroma, _ in voices)
    if weakest < config.cast_consensus_min_chroma:
        return None
    if _hue_spread([hue for _, _, hue in voices]) > config.cast_consensus_max_hue_spread_deg:
        return None
    named = ", ".join(f"{name} C*{chroma:.0f} h{hue:.0f}" for name, chroma, hue in voices)
    return f"no estimate, but every zone is strongly and consistently coloured ({named})"


def _hue_spread(hues: list[float]) -> float:
    """The smallest arc containing every hue angle, in degrees."""
    ordered = sorted(h % 360.0 for h in hues)
    gaps = [b - a for a, b in pairwise(ordered)] + [ordered[0] + 360.0 - ordered[-1]]
    return 360.0 - max(gaps)


__all__ = [
    "SceneEstimate",
    "ScleraEstimate",
    "WhiteBalanceResult",
    "adapt_image",
    "adapt_sample",
    "adapt_samples",
    "apply_white_balance",
    "assess_sclera",
    "estimate_from_scene",
    "illuminant_from_sclera",
    "white_balance_info",
]
