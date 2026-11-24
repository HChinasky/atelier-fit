"""Measuring colour inside a mask.

Every conversion goes through ``colour-science``: sRGB -> XYZ -> CIELAB with the
D65 / CIE 1931 2 degree observer on both ends. We do not hand-roll colour maths.

This module knows nothing about MediaPipe. Its inputs are an RGB array and a
binary mask, which is what makes it testable against synthetic swatches - and
what will let the garment stage reuse it unchanged.
"""

from __future__ import annotations

import math
from collections.abc import Container, Sequence
from dataclasses import dataclass, field, replace
from enum import Enum
from typing import Any

import colour
import cv2
import numpy as np

from .arrays import AnyArray, FloatArray, ImageArray, MaskArray
from .config import EngineConfig
from .models import EyeParts, LabSample, Samples, SkinParts, ZoneStatus
from .zones import EYE_TOO_SMALL, SKIN_ZONES, Zone, Zones


def rgb_to_lab(rgb: AnyArray) -> FloatArray:
    """(..., 3) uint8 or float 0..255 sRGB -> CIELAB, D65 2 degree observer."""
    values = np.asarray(rgb, dtype=np.float64) / 255.0
    xyz = colour.sRGB_to_XYZ(values)
    return np.asarray(colour.XYZ_to_Lab(xyz), dtype=np.float64)


def masked_lab(rgb: ImageArray, mask: MaskArray, config: EngineConfig) -> FloatArray:
    """CIELAB values of the pixels under ``mask``, as an (N, 3) array.

    Zones can run to hundreds of thousands of pixels (hair, mostly). Above
    ``max_pixels_per_zone`` we take a seeded random subsample: a median over
    20k pixels is already stable well past the noise floor of the photo.
    """
    ys, xs = np.nonzero(mask)
    count = ys.size
    if count == 0:
        return np.zeros((0, 3), dtype=np.float64)
    if count > config.max_pixels_per_zone:
        rng = np.random.default_rng(config.sampling_seed)
        picked = rng.choice(count, size=config.max_pixels_per_zone, replace=False)
        ys, xs = ys[picked], xs[picked]
    return rgb_to_lab(rgb[ys, xs])


@dataclass(frozen=True)
class ZoneMeasurement:
    """A zone's sample, and the pixels it was taken over.

    The pixels are kept because "which pixels did you actually measure" is the
    question every mask defect answers, and a filled overlay cannot show it: a
    zone can look perfectly placed and still have had three quarters of itself
    thrown away by a percentile trim nobody can see.
    """

    sample: LabSample | None
    kept: MaskArray
    """Binary, frame-sized, 255 where a pixel survived every filter."""

    clipped_fraction: float = 0.0
    """Share of the zone's pixels with a channel at the 8-bit ceiling.

    Measured BEFORE the percentile trim, which is the only place it can be
    measured: the trim's whole job is to drop the bright tail, so by the time a
    zone is a ``LabSample`` the blown pixels are gone and the sample looks like a
    clean, slightly pale measurement of nothing."""


def measure_zone(
    rgb: ImageArray,
    mask: MaskArray,
    config: EngineConfig,
    *,
    min_pixels: int | None = None,
    reject_chroma_outliers: bool = False,
) -> ZoneMeasurement:
    """``sample_zone``, plus the mask of what survived. See :func:`sample_zone`."""
    limit = config.min_zone_pixels if min_pixels is None else min_pixels
    if int(np.count_nonzero(mask)) < limit:
        return ZoneMeasurement(None, _empty_like(mask))

    ys, xs = _picked(mask, config)
    if ys.size == 0:
        return ZoneMeasurement(None, _empty_like(mask))

    values = rgb[ys, xs]
    clipped = clipped_fraction(values, config)
    lab = rgb_to_lab(values)
    keep = _keep_generic(lab, config, reject_chroma_outliers)
    if not keep.any():
        return ZoneMeasurement(None, _empty_like(mask), clipped)

    return ZoneMeasurement(_summarise(lab[keep]), _mask_from(mask, ys[keep], xs[keep]), clipped)


def clipped_fraction(values: AnyArray, config: EngineConfig) -> float:
    """Share of these pixels with ANY channel at the 8-bit ceiling.

    Any channel and not all three: a highlight on skin clips red first and stays
    coloured-looking in the other two, so waiting for white would miss most of
    the damage. What matters is that one of the three numbers is no longer a
    measurement, because a hue computed from two real channels and one ceiling
    is not the hue of anything.
    """
    pixels = np.asarray(values, dtype=np.float64)
    if pixels.size == 0:
        return 0.0

    return round(float(np.mean(np.any(pixels >= config.clipping_channel_level, axis=-1))), 4)


def _keep_generic(lab: FloatArray, config: EngineConfig, reject_chroma_outliers: bool) -> AnyArray:
    """Which pixels of a generic zone survive the trims."""
    lightness = lab[:, 0]
    low, high = np.percentile(lightness, [config.l_percentile_low, config.l_percentile_high])
    keep = (lightness >= low) & (lightness <= high)

    if reject_chroma_outliers:
        chroma = np.hypot(lab[:, 1], lab[:, 2])
        median = float(np.median(chroma[keep])) if keep.any() else float(np.median(chroma))
        mad = float(np.median(np.abs(chroma - median)))
        tolerance = max(config.chroma_mad_k * 1.4826 * mad, config.chroma_min_spread)
        keep = keep & (np.abs(chroma - median) <= tolerance)

    return np.asarray(keep, dtype=bool)


def _picked(mask: MaskArray, config: EngineConfig) -> tuple[AnyArray, AnyArray]:
    """The pixel coordinates a zone is measured over, subsampled if huge."""
    ys, xs = np.nonzero(mask)
    if ys.size > config.max_pixels_per_zone:
        rng = np.random.default_rng(config.sampling_seed)
        picked = rng.choice(ys.size, size=config.max_pixels_per_zone, replace=False)
        ys, xs = ys[picked], xs[picked]

    return ys, xs


def _mask_from(like: MaskArray, ys: AnyArray, xs: AnyArray) -> MaskArray:
    out = _empty_like(like)
    out[ys, xs] = 255

    return out


def _empty_like(mask: MaskArray) -> MaskArray:
    return np.zeros(mask.shape, dtype=np.uint8)


def sample_zone(
    rgb: ImageArray,
    mask: MaskArray,
    config: EngineConfig,
    *,
    min_pixels: int | None = None,
    reject_chroma_outliers: bool = False,
) -> LabSample | None:
    """Median CIELAB of a zone, after throwing out highlights and shadows.

    ``min_pixels`` is checked against the mask *before* filtering, so it means
    the same thing here as it does in ``zones`` (which has already applied it -
    the parameter exists for direct callers and tests). Returns ``None`` when
    the zone is too small or nothing survives the filters.
    """
    return measure_zone(
        rgb,
        mask,
        config,
        min_pixels=min_pixels,
        reject_chroma_outliers=reject_chroma_outliers,
    ).sample


OVEREXPOSED = "overexposed"
TOO_FEW_PIXELS = "too_few_pixels"
"""The same string as ``ScleraReason.TOO_FEW_PIXELS``: one vocabulary for one
thing, whichever zone ran out."""
"""A zone the camera stopped recording. See :func:`overexposed`."""

CHROMATIC_ZONES: frozenset[str] = frozenset(
    {"skin", "left_cheek", "right_cheek", "forehead", "hair", "iris", "iris_left", "iris_right"}
)
"""Zones that are supposed to carry colour, and so may be judged blown by the
"very light and nearly neutral" signal.

The sclera is deliberately absent. It is bright and near-neutral by nature, so
that test would condemn every healthy one; a clipped sclera is still caught by
the clipping fraction, which is a fact about the pixels rather than about what
the zone is meant to look like."""


def overexposed(sample: LabSample | None, clipped: float, zone: str, config: EngineConfig) -> bool:
    """Has this zone been blown out?

    Two signals, either of which is enough.

    **Clipping.** More than ``zone_clipped_fraction_max`` of the zone has a
    channel at the 8-bit ceiling. That is not a bright colour, it is a missing
    one: the sensor stopped recording and handed back the ceiling.

    **Light and flat.** Very high L* together with collapsed C*. Either alone is
    innocent - fair skin is light, a grey eye is neutral - but together they are
    what a highlight looks like once a resize has averaged the clipped pixels
    back under the ceiling, which is why the first signal alone is not enough.

    The damage is worth naming: a blown zone reads light and almost neutral,
    which is indistinguishable from "cool, light skin" to everything downstream.
    It moves temperature, depth and contrast in the same wrong direction at once.
    """
    if clipped > config.zone_clipped_fraction_max:
        return True

    if sample is None or zone not in CHROMATIC_ZONES:
        return False

    # Via locals: ruff reads the attributes `.L` and `.C` as constants and calls
    # the comparison a Yoda condition whichever way round it is written.
    lightness, chroma = sample.L, sample.C

    return lightness >= config.blown_lightness_min and chroma <= config.blown_chroma_max


class ScleraReason(str, Enum):
    """Why one sclera measurement cannot be used.

    All but the last are decided **here**, per eye, before anything is pooled:
    an eye that fails any of them is not measured into ``samples.sclera``.
    ``eyes_disagree`` is the only pair test and belongs to ``white_balance``,
    which is the only place that needs the two eyes to agree.
    """

    OK = "ok"
    EYE_TOO_SMALL = "eye_too_small"
    """The same string as ``zones.EYE_TOO_SMALL``: the iris was below
    ``min_iris_diameter_px`` in the original frame, so the zone was never built."""

    TOO_FEW_PIXELS = "too_few_pixels"
    TOO_DARK = "too_dark"
    TOO_NOISY = "too_noisy"
    NOT_BRIGHTER_THAN_IRIS = "not_brighter_than_iris"
    """Not lighter than the iris of its own eye - so it is not the white of it."""

    DARKER_THAN_SKIN = "darker_than_skin"
    """Far enough below the skin to be shadow rather than sclera."""

    MORE_CHROMATIC_THAN_SKIN = "more_chromatic_than_skin"
    """Carries more colour than the skin does, which the white of an eye does not."""
    EYES_DISAGREE = "eyes_disagree"


@dataclass(frozen=True)
class ScleraQuality:
    """How much of the sclera mask survived, and whether to trust what is left.

    Deliberately not part of ``LabSample``: the contract is fixed and this is
    diagnostics. It goes to the debug artifacts and the HTML report, and it is
    what ``white_balance`` consults before using the sclera as a white
    reference.

    ``usable`` is the *local* verdict - enough pixels, tight enough, not a lash
    line. It says nothing about the other eye or about the skin; those checks
    live in ``white_balance`` because they need the rest of the face.
    """

    pixels_before: int
    pixels_after: int
    specular_pixels: int
    median_L: float | None
    median_C: float | None
    chroma_base: float | None = None
    """C* of the sclera's own a*b* under the light of the moment. Not a
    threshold - a reading. Under a neutral light it is near zero; under a bulb
    it is large, and that is the cast white balance is there to remove."""

    ab_spread: float | None = None
    """The a*b* distance three quarters of the surviving pixels sit within."""
    reason: ScleraReason = ScleraReason.OK

    @property
    def usable(self) -> bool:
        return self.reason is ScleraReason.OK

    @property
    def dropped_fraction(self) -> float:
        if self.pixels_before <= 0:
            return 0.0
        return round(1.0 - self.pixels_after / self.pixels_before, 4)

    def as_dict(self) -> dict[str, Any]:
        return {
            "pixels_before": self.pixels_before,
            "pixels_after": self.pixels_after,
            "specular_pixels": self.specular_pixels,
            "dropped_fraction": self.dropped_fraction,
            "median_L": self.median_L,
            "median_C": self.median_C,
            "chroma_base": self.chroma_base,
            "ab_spread": self.ab_spread,
            "reason": self.reason.value,
            "usable": self.usable,
        }


@dataclass(frozen=True)
class ScleraSelection:
    """The pixels one eye contributed, plus the sample and verdict over them.

    The pixels are the point. Pooling two eyes means pooling *these*, because
    measuring the union of the two masks again would re-admit everything each
    eye's own filtering threw out.
    """

    lab: FloatArray
    sample: LabSample | None
    quality: ScleraQuality
    kept: MaskArray | None = None
    """Binary, frame-sized, 255 where a pixel survived. None when nothing did."""

    @classmethod
    def nothing(cls, quality: ScleraQuality) -> ScleraSelection:
        """No sample. Whatever reason the caller supplied stands; the default is
        that there were not enough pixels to measure."""
        if quality.reason is ScleraReason.OK:
            quality = replace(quality, reason=ScleraReason.TOO_FEW_PIXELS)
        return cls(lab=np.zeros((0, 3), dtype=np.float64), sample=None, quality=quality)

    @property
    def usable(self) -> bool:
        return self.sample is not None and self.quality.usable


@dataclass(frozen=True)
class ScleraSide:
    """One eye, measured and judged on its own.

    Every per-eye check has already run by the time this exists, so
    ``usable`` is the whole verdict: geometry, pixel counts, spread, the sanity
    floor and brighter-than-skin. Only the pair test is left for
    ``white_balance``.
    """

    side: str
    sample: LabSample | None
    quality: ScleraQuality

    @property
    def usable(self) -> bool:
        return self.sample is not None and self.quality.usable


@dataclass(frozen=True)
class Measurements:
    """What the sampling step produces: the contract samples plus diagnostics."""

    samples: Samples
    sclera_quality: ScleraQuality | None = None
    """The pooled sclera, as reported to the user."""

    sclera_sides: tuple[ScleraSide, ...] = ()
    """Each eye on its own. White balance compares them; agreeing on colour is
    the strongest evidence that both masks really are on sclera."""

    overexposed: frozenset[str] = frozenset()
    """Zones the camera stopped recording. Their samples are still reported - the
    numbers are the evidence, and "L* 91.9, C* 2.2" says far more to a reviewer
    than a blank - but nothing downstream may MEASURE with them."""

    clipped_fractions: dict[str, float] = field(default_factory=dict)
    """Per zone, the share of pixels at the ceiling. Debug only."""

    skin_shadow_fraction: float | None = None
    """Share of the skin mask sitting below ``low_light_shadow_l``. The quality
    gate needs it to tell a dark frame from dark skin."""

    sampled_masks: dict[str, MaskArray] = field(default_factory=dict)
    """Per zone, the pixels that survived EVERY filter. Built only for a debug
    run - they are frame-sized - and never serialised. "Which pixels did you
    actually measure" is the question every mask defect answers, and a filled
    overlay cannot show it: a zone can look perfectly placed and still have had
    three quarters of itself thrown away by a trim nobody can see."""


def sample_zones(
    rgb: ImageArray, zones: Zones, config: EngineConfig, *, keep_masks: bool = False
) -> Measurements:
    """Measure every zone the pipeline produced.

    ``keep_masks`` also records which pixels each zone was measured over. Off by
    default because those masks are frame-sized and only a debug run wants them.
    """
    sampled: dict[str, MaskArray] = {}
    blown: set[str] = set()
    clipping: dict[str, float] = {}

    def remember(name: str, measurement: ZoneMeasurement) -> LabSample | None:
        if keep_masks and measurement.sample is not None:
            sampled[name] = measurement.kept
        clipping[name] = measurement.clipped_fraction
        if overexposed(measurement.sample, measurement.clipped_fraction, name, config):
            blown.add(name)

        return measurement.sample

    def take(name: str, *, small: bool = False, skin: bool = False) -> LabSample | None:
        zone: Zone | None = zones.get(name)
        if zone is None:
            return None

        return remember(
            name,
            measure_zone(
                rgb,
                zone.mask,
                config,
                min_pixels=config.min_small_zone_pixels if small else config.min_zone_pixels,
                reject_chroma_outliers=skin,
            ),
        )

    def take_iris(name: str) -> ZoneMeasurement | None:
        zone: Zone | None = zones.get(name)
        if zone is None:
            return None
        measurement = measure_iris(rgb, zone.mask, config)
        remember(name, measurement)

        return measurement

    parts = SkinParts(
        left_cheek=take("left_cheek", skin=True),
        right_cheek=take("right_cheek", skin=True),
        forehead=take("forehead", skin=True),
    )
    # Pooled from the sub-zones that are still a measurement. A blown cheek
    # left in would drag the aggregate light and neutral - which is the whole
    # failure this is here to stop, and it would do it silently.
    skin = aggregate_skin(parts, skip=blown)
    # Judged on the clipping of the sub-zones it was pooled FROM. Counting a
    # blown forehead's clipping here flagged the aggregate too, and one blown
    # sub-zone - survivable by design (quality.overexposure) - refused the
    # whole photo: a phone screenshot with a bright forehead and two good
    # cheeks was turned away.
    pooled = [clipping.get(z, 0.0) for z in SKIN_ZONES if z not in blown]
    if overexposed(skin, max(pooled, default=0.0), "skin", config):
        blown.add("skin")
    if keep_masks and skin is not None:
        # samples.skin is the median of the three sub-zone medians, not a
        # measurement of the union mask - so the pixels behind it are the ones
        # those three were measured over, and nothing else.
        contributing = [sampled[name] for name in SKIN_ZONES if name in sampled]
        if contributing:
            sampled["skin"] = np.maximum.reduce(contributing)

    # Each eye on its own, then the pool from the ones that passed - the same
    # order the sclera has used since pooling-before-judging put pixels from a
    # rejected eye into the contract. The iris had never had it: the pooled zone
    # was measured in one go over the union of the masks, so a side that would
    # have failed its own checks was measured into ``samples.iris`` anyway, and
    # ``zones`` reported the two sides on geometry alone.
    iris_sides = {side: take_iris(f"iris_{side}") for side in ("left", "right")}
    iris_usable = {
        side: measurement is not None
        and measurement.sample is not None
        and f"iris_{side}" not in blown
        for side, measurement in iris_sides.items()
    }
    iris = _pool_irises(rgb, iris_sides, iris_usable, config)
    if keep_masks and iris is not None:
        contributing = [
            m.kept for side, m in iris_sides.items() if m is not None and iris_usable[side]
        ]
        if contributing:
            sampled["iris"] = np.maximum.reduce(contributing)
    iris_clipping = max(clipping.get(f"iris_{s}", 0.0) for s in ("left", "right"))
    if overexposed(iris, iris_clipping, "iris", config):
        blown.add("iris")

    # Each eye's sclera is judged against that eye's own iris, so the two have
    # to be measured first. They are.
    iris_samples: dict[str, LabSample | None] = {
        side: _side_sample(iris_sides[side], iris_usable[side]) for side in ("left", "right")
    }
    sides, sclera, sclera_quality = measure_scleras(rgb, zones, skin, iris_samples, config)
    if keep_masks:
        sampled |= sclera_masks(rgb, zones, skin, iris_samples, config)

    return Measurements(
        samples=Samples(
            skin=skin,
            skin_parts=parts,
            hair=take("hair"),
            iris=iris,
            # Whatever was measured, whether or not it was then used. A side
            # that failed its checks is the one a reviewer most needs the
            # numbers for: "darker_than_skin" says nothing about HOW MUCH
            # darker, and a rule that nobody can see the margin on cannot be
            # argued with. The verdict travels beside them in ``zones``.
            iris_parts=EyeParts(
                left=_measured_sample(iris_sides["left"]),
                right=_measured_sample(iris_sides["right"]),
            ),
            sclera=sclera,
            sclera_parts=EyeParts(
                left=next((s.sample for s in sides if s.side == "left"), None),
                right=next((s.sample for s in sides if s.side == "right"), None),
            ),
        ),
        sclera_quality=sclera_quality,
        sclera_sides=sides,
        overexposed=frozenset(blown),
        clipped_fractions=clipping,
        skin_shadow_fraction=shadow_fraction(rgb, zones, config),
        sampled_masks=sampled,
    )


def _side_sample(measurement: ZoneMeasurement | None, usable: bool) -> LabSample | None:
    """A side's sample, or None when the side is not to be MEASURED WITH.

    Not what the contract reports - that is :func:`_measured_sample`, which
    keeps the numbers whatever the verdict. This is for the places that are
    about to compute with a sample: the pooled iris, and the iris each sclera
    is judged against. A rejected eye must not do either.
    """
    return measurement.sample if usable and measurement is not None else None


def _measured_sample(measurement: ZoneMeasurement | None) -> LabSample | None:
    """A side's sample if one was measured at all. ``None`` only when there was
    nothing to measure - no zone, or not enough pixels inside it."""
    return measurement.sample if measurement is not None else None


def _pool_irises(
    rgb: ImageArray,
    sides: dict[str, ZoneMeasurement | None],
    usable: dict[str, bool],
    config: EngineConfig,
) -> LabSample | None:
    """Both eyes as one sample, over the pixels each eye kept.

    Over the KEPT pixels and not over the union of the two zone masks: the
    masks still hold everything the catchlight filter and the percentile trim
    threw away, and measuring them again would put all of it back.
    """
    kept = [
        m.kept
        for side, m in sides.items()
        if m is not None and m.sample is not None and usable[side]
    ]
    if not kept:
        return None

    union = kept[0] if len(kept) == 1 else np.maximum.reduce(kept)
    ys, xs = np.nonzero(union)
    if ys.size < config.min_small_zone_pixels:
        return None

    return _summarise(rgb_to_lab(rgb[ys, xs]))


def sclera_masks(
    rgb: ImageArray,
    zones: Zones,
    skin: LabSample | None,
    irises: dict[str, LabSample | None],
    config: EngineConfig,
) -> dict[str, MaskArray]:
    """The pixels each sclera was measured over, and their union for the pool.

    The pooled sclera is measured over the union of the eyes that passed, which
    is exactly what the pooled sample is built from - so the picture and the
    number cannot disagree.
    """
    out: dict[str, MaskArray] = {}
    union: MaskArray | None = None

    for side in ("left", "right"):
        zone = zones.get(f"sclera_{side}")
        if zone is None:
            continue
        picked = _judged(select_sclera(rgb, zone.mask, config), skin, irises.get(side), config)
        if picked.kept is None or picked.sample is None:
            continue
        out[f"sclera_{side}"] = picked.kept
        if picked.usable:
            union = picked.kept if union is None else np.maximum(union, picked.kept)

    if union is not None:
        out["sclera"] = union

    return out


def measure_scleras(
    rgb: ImageArray,
    zones: Zones,
    skin: LabSample | None,
    irises: dict[str, LabSample | None],
    config: EngineConfig,
) -> tuple[tuple[ScleraSide, ...], LabSample | None, ScleraQuality | None]:
    """Measure each eye, judge it, and pool only the ones that passed.

    The order is the whole point. Every per-eye check runs first - the geometry
    ``zones`` already applied, then pixel counts, spread, the sanity floor, and
    brighter-than-skin - and only then is anything pooled. Pooling first and
    judging afterwards is what put pixels from an eye we had just called
    unusable into ``samples.sclera``, which is the contract.

    Brighter-than-skin lives here rather than in ``white_balance`` because it is
    a per-eye fact about this face, and the pooled sample has to respect it too.
    """
    sides: list[ScleraSide] = []
    picks: list[ScleraSelection] = []
    for side in ("left", "right"):
        zone = zones.get(f"sclera_{side}")
        if zone is None:
            # The zone was never built, or was built and failed its geometry.
            # Carry the reason so the report can say which.
            picked = ScleraSelection.nothing(
                ScleraQuality(0, 0, 0, None, None, reason=_zone_reason(zones, f"sclera_{side}"))
            )
        else:
            picked = _judged(select_sclera(rgb, zone.mask, config), skin, irises.get(side), config)
        picks.append(picked)
        sides.append(ScleraSide(side=side, sample=picked.sample, quality=picked.quality))

    if not picks:  # pragma: no cover - the loop always runs twice
        return (), None, None
    return tuple(sides), *_pool_scleras(picks, config)


CIELAB_OFFSET = 16.0
"""The 16 in ``L* = 116 (Y/Yn)**(1/3) - 16``. ``L* + 16`` is the quantity that
scales with the cube root of luminance, so it is the one to scale a shade by."""


def _under_skin_tolerance(skin_lightness: float, config: EngineConfig) -> float:
    """How far under the skin the white of an eye may read, on THIS face.

    A fixed drop in L* is not a fixed amount of shade. Above the toe of the
    curve ``L* + 16`` goes as the cube root of luminance, so a surface lit by a
    fraction ``k`` of the light on the cheek reads

        dL = (L_skin + 16) * (1 - k ** (1/3))

    - a drop PROPORTIONAL to ``L_skin + 16``. Read the other way: a constant
    tolerance of 6 L* is a shadow of 0.67 under skin at L* 32 and one of 0.83
    under skin at L* 85. Same number, half the permitted shade, and the strict
    end of it landed on exactly the faces where the eye socket is furthest below
    the cheek in L*. It is why a frame with skin at L* 84.8 refused both eyes.

    So the tolerance is stated as the ratio and converted, which is one claim
    about anatomy - the brow and the lids shade the socket by roughly the same
    fraction whoever the face belongs to - instead of a different implied claim
    at every skin tone.

    ``sclera_under_skin_max_l`` stays as a floor under it, but say plainly what
    that is worth: at 0.55 the ratio only asks for less than 6 L* below skin at
    about L* 17, which is darker than skin gets. **Every skin tone is loosened,
    deep skin included** - the floor moves from 26.0 to 23.3 on skin at L* 32,
    and from 78.8 to 66.6 on the frame this was written for. What does not move
    on deep skin is the verdicts: what catches lid shadow there is the iris test
    and the chroma test, and both are untouched. The floor is kept because a
    ratio alone would collapse to nothing as the skin approaches black, where
    the cube root stops describing CIELAB at all.

    This is the LOOSENED form, and it is only reached when the eye's own iris
    was measured - see :func:`_judged`.
    """
    shaded = (skin_lightness + CIELAB_OFFSET) * (
        1.0 - float(config.sclera_socket_light_ratio) ** (1.0 / 3.0)
    )

    return max(config.sclera_under_skin_max_l, float(shaded))


def _judged(
    picked: ScleraSelection,
    skin: LabSample | None,
    iris: LabSample | None,
    config: EngineConfig,
) -> ScleraSelection:
    """Is this sample really the white of the eye, or is it lid shadow?

    It used to be one test - brighter than the skin by a margin - and that was
    wrong at both ends of the skin-tone axis. Unsatisfiable on very light or
    overexposed skin: the first live frame measured skin at L* 86.9 and refused
    both eyes, because nothing in an eye socket is 5 L* above that. And far too
    easy on deep skin, where almost anything in the socket clears L* 35,
    including the shadow the test exists to reject.

    The purpose was never "compare it with the cheek". It was to reject lid
    shadow, and lid shadow is best recognised inside the eye:

    1. **Lighter than its own iris**, by ``sclera_over_iris_min_l``. The strong
       test, and the only one entirely internal to the eye - the white is the
       lightest thing in there whatever the skin tone and whatever the exposure.
       Skipped when that eye's iris could not be measured.
    2. **Not much darker than the skin.** The old test, inverted and loosened
       into the direction that is actually diagnostic. Deep lid shadow sits
       20-30 L* under the cheek and is still caught; a sclera a few L* under
       very light skin is normal and passes.

       How much is "much" depends on whether (1) ran. **With an iris** this is
       the weaker of the two and is stated as shade rather than as a number of
       L*: the sclera may read down to ``sclera_socket_light_ratio`` of the
       skin's luminance, never stricter than ``sclera_under_skin_max_l`` - see
       :func:`_under_skin_tolerance`. **Without one** it is the whole of the
       evidence outside the eye and stays at the constant.

       The looser form is not a concession, it is the correction: a constant
       tolerance in L* means a different amount of shade at every skin tone, and
       its strict end sat on fair skin - a frame with skin at L* 84.8 had both
       eyes refused as lid shadow while each sclera cleared its own iris, at
       L* 32.7 and L* 38.2, by a wide margin.
    3. **Markedly less chromatic than the skin** - under ``sclera_chroma_ratio``
       of it, but never held below ``sclera_chroma_floor`` in absolute terms.
       Markedly, and not merely "no more chromatic than": a sample that landed
       on lid skin carries the skin's OWN chroma, so a tolerance-shaped test
       waves it through, which is the failure this exists to catch. Relative to
       the skin in the same photograph, so a colour cast moves both and cancels;
       the floor keeps it satisfiable on low-chroma skin.

    All three must hold, and they catch different things. (1) alone passes a
    sample that landed on the bright lower lid; (3) rejects it, because lid skin
    carries the skin's colour. (2) alone passes a bright warm patch; (3) rejects
    that too. (3) alone passes deep neutral shadow; (1) and (2) reject it.

    **With no usable iris only (2) and (3) remain**, which is weaker - a shallow
    lid shadow on deep skin could slip through. That is the honest state of it:
    the eye whose iris could not be measured is also the eye least likely to be
    worth trusting, and the alternative - refusing every sclera whose iris is
    missing - would throw away good measurements to avoid a rare bad one. It is
    also why (2) stays strict there: it is carrying the weight alone.

    The converse is the trade in the loosened form. With an iris under it, (2)
    admits a neutral shadow that sits well above that iris - on mid skin the
    floor moves from L* 60 to L* 51. That is the intended order of evidence:
    the iris test is the one that knows what an eye looks like, and (2) is a
    backstop against shadow deep enough that no eye reads that way at all.
    """
    if picked.sample is None or not picked.quality.usable:
        return picked

    sample = picked.sample

    def fail(reason: ScleraReason) -> ScleraSelection:
        return replace(picked, quality=replace(picked.quality, reason=reason))

    # Via locals throughout: ruff reads the attributes `.L` and `.C` as
    # constants and rewrites every comparison that mentions them into Yoda form.
    lightness, chroma = sample.L, sample.C

    if iris is not None and lightness - iris.L < config.sclera_over_iris_min_l:
        return fail(ScleraReason.NOT_BRIGHTER_THAN_IRIS)

    if skin is not None:
        skin_lightness, skin_chroma = skin.L, skin.C
        # Loosened where there is an iris to lean on, strict where there is not.
        tolerance = (
            _under_skin_tolerance(skin_lightness, config)
            if iris is not None
            else config.sclera_under_skin_max_l
        )
        if skin_lightness - lightness > tolerance:
            return fail(ScleraReason.DARKER_THAN_SKIN)
        limit = max(skin_chroma * config.sclera_chroma_ratio, config.sclera_chroma_floor)
        if chroma > limit:
            return fail(ScleraReason.MORE_CHROMATIC_THAN_SKIN)

    return picked


def _pool_scleras(
    picks: Sequence[ScleraSelection], config: EngineConfig
) -> tuple[LabSample | None, ScleraQuality]:
    """Both eyes' surviving pixels as one sample, or one eye's, or none.

    Built from the pixels each eye kept, never by re-filtering the union of the
    masks: the union would re-admit exactly what each eye's own filtering threw
    out.
    """
    qualities = [pick.quality for pick in picks]
    before = sum(q.pixels_before for q in qualities)
    specular = sum(q.specular_pixels for q in qualities)

    kept = [pick.lab for pick in picks if pick.usable and pick.lab.shape[0]]
    if not kept:
        return None, ScleraQuality(
            pixels_before=before,
            pixels_after=0,
            specular_pixels=specular,
            median_L=None,
            median_C=None,
            reason=worst_reason(qualities),
        )

    lab = np.vstack(kept) if len(kept) > 1 else kept[0]
    sample = _summarise(lab)
    base_ab = np.median(lab[:, 1:], axis=0)
    distance = np.linalg.norm(lab[:, 1:] - base_ab, axis=1)
    return sample, ScleraQuality(
        pixels_before=before,
        pixels_after=int(lab.shape[0]),
        specular_pixels=specular,
        median_L=sample.L,
        median_C=sample.C,
        chroma_base=round(float(np.hypot(*base_ab)), 3),
        ab_spread=round(float(np.percentile(distance, config.sclera_ab_spread_percentile)), 3),
        reason=ScleraReason.OK,
    )


def _zone_reason(zones: Zones, name: str) -> ScleraReason:
    """Turn the zone builder's verdict into this module's vocabulary."""
    zone = zones.zones.get(name)
    if zone is not None and zone.reason == EYE_TOO_SMALL:
        return ScleraReason.EYE_TOO_SMALL
    return ScleraReason.TOO_FEW_PIXELS


CONTRACT_ZONES: tuple[str, ...] = (
    "skin",
    "left_cheek",
    "right_cheek",
    "forehead",
    "hair",
    "iris",
    "iris_left",
    "iris_right",
    "sclera",
    "sclera_left",
    "sclera_right",
)
"""Every zone the contract reports a verdict for, in the order a report reads
best. The engine's own names throughout - inventing ``left_iris`` for the
contract alone would leave two vocabularies for one thing."""


def zone_statuses(zones: Zones, measured: Measurements) -> dict[str, ZoneStatus]:
    """Why each zone did or did not produce a sample.

    Two sources, because two different things can go wrong. ``zones`` knows
    about GEOMETRY - a mask that came out too small, an eye below the resolution
    floor - and is the only source for skin and hair. The sclera knows more:
    selection inside a mask that was big enough can still leave too little, or
    leave something too dark, too wide in a*b*, or darker than the skin around
    it. Where both have an opinion the sclera's is the specific one and wins.
    """
    geometry = zones.reasons
    statuses: dict[str, ZoneStatus] = {}

    # A side that produced no sample has a reason, always. Geometry knows about
    # a mask too small or an eye below the resolution floor; anything that
    # survived geometry and still measured nothing ran out of pixels inside its
    # own mask, which is the same answer the sclera gives.
    #
    # Reading usability off "is there a sample" is safe HERE and nowhere else.
    # ``samples`` keeps a rejected zone's numbers, so the two part company in
    # general - but an iris side has only two ways to fail, no pixels and
    # overexposure, and the second is handled above. Give the iris a colour
    # check of its own and this has to grow a verdict to read, the way the
    # sclera already does.
    iris_reasons: dict[str, str | None] = {}
    for side in ("left", "right"):
        name = f"iris_{side}"
        if geometry.get(name) is not None or name in measured.overexposed:
            continue
        measuredsample = getattr(measured.samples.iris_parts, side)
        iris_reasons[name] = None if measuredsample is not None else TOO_FEW_PIXELS

    sides = {side.side: side for side in measured.sclera_sides}
    sclera_reasons: dict[str, str | None] = {
        f"sclera_{name}": (None if side.usable else side.quality.reason.value)
        for name, side in sides.items()
    }
    if measured.sclera_quality is not None:
        sclera_reasons["sclera"] = (
            None if measured.sclera_quality.usable else measured.sclera_quality.reason.value
        )

    for name in CONTRACT_ZONES:
        # Overexposure first. A zone the camera stopped recording has no colour
        # to report whatever else is or is not wrong with it, and saying
        # "too few pixels" about a blown cheek sends the reviewer to the mask.
        reason = (
            OVEREXPOSED
            if name in measured.overexposed
            else sclera_reasons.get(name, iris_reasons.get(name, geometry.get(name)))
        )
        statuses[name] = ZoneStatus(usable=reason is None, reason=reason)

    return statuses


def shadow_fraction(rgb: ImageArray, zones: Zones, config: EngineConfig) -> float | None:
    """Share of the skin mask sitting in deep shadow, before any correction.

    Not derivable from the samples: ``sample_zone`` drops the dark tail on
    purpose, so by the time skin reaches a ``LabSample`` the evidence of how
    much of the face was in the dark is gone. The quality gate needs it.
    """
    zone = zones.get("skin")
    if zone is None:
        return None
    lab = masked_lab(rgb, zone.mask, config)
    if lab.shape[0] == 0:
        return None
    return round(float(np.mean(lab[:, 0] < config.low_light_shadow_l)), 4)


def select_sclera(rgb: ImageArray, mask: MaskArray, config: EngineConfig) -> ScleraSelection:
    """Pick the pixels that are the white of the eye, and judge what is left.

    The sclera is the one zone where the signal lives at the bright end of its
    own distribution, so ``sample_zone``'s symmetric percentile trim would do
    exactly the wrong thing. Instead:

    1. keep the upper part of the zone by L*. The darker half of any sclera mask
       is lid, lash, or the shadow they cast. Lightness is the only separator
       that survives a change of illuminant: the sclera is the brightest thing
       in the eye opening under a window and under a bulb alike, while a chroma
       cap would be wrong under one or the other - CIELAB compresses chroma at
       low lightness, so a warm bulb leaves the sclera at C* 60 and the dark lid
       beside it at C* 25;
    2. take the median a*b* of what is left. That is the sclera's colour under
       whatever light this photo was taken in, and everything below is measured
       as a distance from it rather than in absolute C*;
    3. remove catchlights - small connected blobs, very bright, sitting close to
       that a*b*, which are a picture of the lamp rather than of the eye;
    4. drop pixels far from it in a*b*: eyeliner, the caruncle, a lash edge.

    The surviving pixels come back with the sample, because pooling two eyes
    means pooling *those pixels* - measuring the union of the two masks again
    would re-admit everything each eye's own filtering threw out.

    A narrow fissure, a squint or a closed eye is a normal photo, not an error:
    the zone just comes back with no sample and the pipeline carries on.
    """
    ys, xs = np.nonzero(mask)
    before = int(ys.size)
    if before == 0:
        return ScleraSelection.nothing(ScleraQuality(0, 0, 0, None, None))

    lab = rgb_to_lab(rgb[ys, xs])
    floor = float(np.percentile(lab[:, 0], config.sclera_l_percentile_low))
    bright = lab[:, 0] >= floor
    if not bright.any():  # pragma: no cover - a percentile always keeps something
        return ScleraSelection.nothing(ScleraQuality(before, 0, 0, None, None))

    base_ab = np.median(lab[bright, 1:], axis=0)
    distance = np.linalg.norm(lab[:, 1:] - base_ab, axis=1)
    base_chroma = round(float(np.hypot(*base_ab)), 3)

    specular = _specular_pixels(mask, ys, xs, lab[:, 0], distance, config)
    keep = bright & ~specular & (distance <= config.sclera_max_ab_distance)

    selected = np.asarray(lab[keep], dtype=np.float64)
    if selected.shape[0] < config.min_small_zone_pixels:
        return ScleraSelection.nothing(
            ScleraQuality(
                before,
                int(selected.shape[0]),
                int(specular.sum()),
                None,
                None,
                base_chroma,
            )
        )

    sample = _summarise(selected)
    ab_spread = float(np.percentile(distance[keep], config.sclera_ab_spread_percentile))

    median_lightness = sample.L
    if median_lightness < config.sclera_min_l_floor:
        reason = ScleraReason.TOO_DARK
    elif ab_spread > config.sclera_max_ab_spread:
        reason = ScleraReason.TOO_NOISY
    else:
        reason = ScleraReason.OK

    return ScleraSelection(
        lab=selected,
        kept=_mask_from(mask, ys[keep], xs[keep]),
        sample=sample,
        quality=ScleraQuality(
            pixels_before=before,
            pixels_after=int(selected.shape[0]),
            specular_pixels=int(specular.sum()),
            median_L=sample.L,
            median_C=sample.C,
            chroma_base=base_chroma,
            ab_spread=round(ab_spread, 3),
            reason=reason,
        ),
    )


def sample_sclera(
    rgb: ImageArray, mask: MaskArray, config: EngineConfig
) -> tuple[LabSample | None, ScleraQuality]:
    """One mask's sclera sample and its verdict. See :func:`select_sclera`."""
    picked = select_sclera(rgb, mask, config)
    return picked.sample, picked.quality


def sample_iris(rgb: ImageArray, mask: MaskArray, config: EngineConfig) -> LabSample | None:
    """See :func:`measure_iris`."""
    return measure_iris(rgb, mask, config).sample


def measure_iris(rgb: ImageArray, mask: MaskArray, config: EngineConfig) -> ZoneMeasurement:
    """Measure the iris, with the catchlight taken out.

    Almost the generic path - percentile trim, median - with one addition: a
    glint is a picture of the lamp sitting in the middle of the thing being
    measured, and on a dark iris it is far brighter than anything else in the
    zone, so it survives the percentile trim and drags the median toward the
    light source.

    Nothing is cut by absolute darkness. A brown iris IS dark, all of it, and a
    floor tuned so a blue eye reads well would leave a brown one with nothing to
    measure. The trim that does run is a percentile inside the zone, which
    adapts to whatever eye it is given.
    """
    if int(np.count_nonzero(mask)) < config.min_small_zone_pixels:
        return ZoneMeasurement(None, _empty_like(mask))

    ys, xs = _picked(mask, config)
    lab = rgb_to_lab(rgb[ys, xs])
    if lab.shape[0] == 0:  # pragma: no cover - the count above already checked
        return ZoneMeasurement(None, _empty_like(mask))

    clipped = clipped_fraction(rgb[ys, xs], config)
    keep = ~_iris_specular(mask, ys, xs, lab, config)
    if int(keep.sum()) < config.min_small_zone_pixels:
        # The glint filter should never eat a zone. If it did, the blob was not
        # a glint and the honest thing is to measure what is there.
        keep = np.ones(lab.shape[0], dtype=bool)

    trimmed = _keep_generic(lab[keep], config, reject_chroma_outliers=False)
    keep[np.flatnonzero(keep)[~trimmed]] = False
    if not keep.any():  # pragma: no cover - a percentile always keeps something
        return ZoneMeasurement(None, _empty_like(mask))

    return ZoneMeasurement(_summarise(lab[keep]), _mask_from(mask, ys[keep], xs[keep]), clipped)


def _iris_specular(
    mask: MaskArray, ys: AnyArray, xs: AnyArray, lab: FloatArray, config: EngineConfig
) -> AnyArray:
    """Flag catchlights on an iris: small connected blobs, bright and washed out.

    Both halves are relative to the zone, because a brown iris and a blue one
    share no absolute threshold - what they do share is that the reflection of a
    lamp is brighter and less coloured than the eye it is sitting on.

    Connectivity matters as much as it does for the sclera: the bright rim of a
    pale iris is a large connected region and has to survive, or the measurement
    loses exactly the pixels that carry the colour.
    """
    lightness, chroma = lab[:, 0], np.hypot(lab[:, 1], lab[:, 2])
    candidate = (lightness >= np.percentile(lightness, config.iris_specular_percentile)) & (
        chroma <= np.median(chroma)
    )
    if not candidate.any():
        return np.zeros(ys.size, dtype=bool)

    canvas = np.zeros(mask.shape, dtype=np.uint8)
    canvas[ys[candidate], xs[candidate]] = 255
    count, labels, stats, _ = cv2.connectedComponentsWithStats(canvas, connectivity=8)

    area_limit = config.iris_specular_max_area * float(np.count_nonzero(mask))
    glints = {
        index for index in range(1, count) if float(stats[index, cv2.CC_STAT_AREA]) <= area_limit
    }
    if not glints:
        return np.zeros(ys.size, dtype=bool)

    return np.isin(labels[ys, xs], list(glints))


def worst_reason(qualities: Sequence[ScleraQuality]) -> ScleraReason:
    """The most informative reason among several failures.

    ``too_few_pixels`` is the least useful thing to be told - a closed eye, a
    squint and a mask that missed all report it - so any other reason wins.
    """
    for quality in qualities:
        if quality.reason not in (ScleraReason.OK, ScleraReason.TOO_FEW_PIXELS):
            return quality.reason
    return ScleraReason.TOO_FEW_PIXELS


def _specular_pixels(
    mask: MaskArray,
    ys: AnyArray,
    xs: AnyArray,
    lightness: FloatArray,
    distance: FloatArray,
    config: EngineConfig,
) -> AnyArray:
    """Flag catchlights: small connected blobs, very bright, sitting close to
    the sclera's own a*b*.

    Connectivity is the point. A bright region covering a good share of the mask
    is the sclera doing its job; a bright *speck* is the reflection of a window
    or a ring light. Closeness is measured against the sclera's colour rather
    than against neutral, because a catchlight under a warm bulb is warm.
    """
    candidate = (lightness >= config.sclera_specular_l) & (
        distance <= config.sclera_specular_max_ab_distance
    )
    if not candidate.any():
        return np.zeros(ys.size, dtype=bool)

    canvas = np.zeros(mask.shape, dtype=np.uint8)
    canvas[ys[candidate], xs[candidate]] = 255
    count, labels, stats, _ = cv2.connectedComponentsWithStats(canvas, connectivity=8)

    area_limit = config.sclera_specular_max_area * float(np.count_nonzero(mask))
    glints = {
        index for index in range(1, count) if float(stats[index, cv2.CC_STAT_AREA]) <= area_limit
    }
    if not glints:
        return np.zeros(ys.size, dtype=bool)

    component_of = labels[ys, xs]
    return np.isin(component_of, list(glints))


def aggregate_skin(parts: SkinParts, skip: Container[str] = frozenset()) -> LabSample | None:
    """Combine the three skin sub-zones, weighting each one equally.

    Not a pooled median over the union mask: the forehead polygon is several
    times the area of either cheek, so pooling would make "skin colour" mean
    "forehead colour" - and the forehead is the oiliest, most sun-exposed and
    most strongly lit of the three. Taking the median of the three medians gives
    the cheeks, which carry the undertone, their say.
    """
    named = (
        ("left_cheek", parts.left_cheek),
        ("right_cheek", parts.right_cheek),
        ("forehead", parts.forehead),
    )
    available = [p for name, p in named if p and name not in skip]
    if not available:
        return None

    lightness = float(np.median([p.L for p in available]))
    a = float(np.median([p.a for p in available]))
    b = float(np.median([p.b for p in available]))
    return LabSample(
        L=round(lightness, 3),
        a=round(a, 3),
        b=round(b, 3),
        C=round(math.hypot(a, b), 3),
        h=round(math.degrees(math.atan2(b, a)) % 360.0, 3),
        pixels=sum(p.pixels for p in available),
        # The widest spread of any sub-zone, not the spread of the combination:
        # this is a "how uniform was the skin we measured" signal, and the worst
        # sub-zone is the honest answer.
        L_iqr=round(max(p.L_iqr for p in available), 3),
    )


def _drop_highlights_and_shadows(lab: FloatArray, config: EngineConfig) -> FloatArray:
    """Trim the L* tails: specular highlights on the nose and forehead read as
    the light source's colour, not the skin's, and deep shadows read as noise."""
    lightness = lab[:, 0]
    low, high = np.percentile(lightness, [config.l_percentile_low, config.l_percentile_high])
    keep = (lightness >= low) & (lightness <= high)
    return np.asarray(lab[keep], dtype=np.float64)


def _drop_chroma_outliers(lab: FloatArray, config: EngineConfig) -> FloatArray:
    """Skin only: drop pixels whose chroma is far from the zone median.

    Robust (median + MAD) rather than mean + sigma, because the contaminants we
    are removing - a lipstick smear, a mole, a stray hair - are exactly the kind
    of heavy tail that drags a mean along with it.
    """
    chroma = np.hypot(lab[:, 1], lab[:, 2])
    median = float(np.median(chroma))
    mad = float(np.median(np.abs(chroma - median)))
    # 1.4826 * MAD estimates sigma for normally distributed data. The floor
    # matters on flat zones, where MAD collapses to zero even though outliers
    # are sitting right there.
    tolerance = max(config.chroma_mad_k * 1.4826 * mad, config.chroma_min_spread)
    return np.asarray(lab[np.abs(chroma - median) <= tolerance], dtype=np.float64)


def _summarise(lab: FloatArray) -> LabSample:
    lightness, a, b = (float(np.median(lab[:, i])) for i in range(3))
    q1, q3 = np.percentile(lab[:, 0], [25.0, 75.0])
    hue = math.degrees(math.atan2(b, a)) % 360.0
    return LabSample(
        L=round(lightness, 3),
        a=round(a, 3),
        b=round(b, 3),
        C=round(math.hypot(a, b), 3),
        h=round(hue, 3),
        pixels=int(lab.shape[0]),
        L_iqr=round(float(q3 - q1), 3),
    )
