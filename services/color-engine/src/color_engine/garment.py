"""Garment colour: what colours a catalog product actually is.

A product photo comes in one of two kinds, and each is isolated differently:

- **worn** - a shop photo of the garment on a model. The selfie segmenter
  already knows a ``clothes`` class; when it also sees a person (hair or skin),
  that class is the garment.
- **flat** - the garment alone on a studio backdrop. The segmenter is trained
  on people and says little here, so the backdrop is estimated from the frame's
  border and everything far enough from it is the garment.

The pixels are then measured with the *same* conversion the face is measured
with (``sampling.rgb_to_lab``): a garment and a cheek have to be on one scale,
or "does this colour suit her" means nothing. The colours are clustered, near
twins merged (one fabric in light and in shadow), and reported by share.

What this module does NOT decide is whether a colour suits anybody. That is a
comparison with a season's palette, and the palettes live with the caller.

Known blind spot: a garment the colour of its backdrop (a white dress on white)
has no edge to find on a flat shot and comes back ``no_garment``. That is a
refusal, stated as one, rather than a measurement of the backdrop.
"""

from __future__ import annotations

from enum import Enum
from typing import Any

import colour
import cv2
import numpy as np
from pydantic import BaseModel, ConfigDict, Field

from .arrays import FloatArray, ImageArray, MaskArray
from .config import EngineConfig
from .detect import SegmentClass
from .errors import ImageInputError
from .io import load_image
from .sampling import masked_lab, rgb_to_lab

GARMENT_ALGO_VERSION = "garment-0.1.0"


class GarmentStatus(str, Enum):
    OK = "ok"
    REJECTED = "rejected"


class GarmentRejectReason(str, Enum):
    UNSUPPORTED_FORMAT = "unsupported_format"
    FILE_TOO_LARGE = "file_too_large"
    LOW_RESOLUTION = "low_resolution"
    NO_GARMENT = "no_garment"


class GarmentMethod(str, Enum):
    SEGMENTER = "segmenter"
    """Worn: the segmenter's clothes class."""
    BACKGROUND = "background"
    """Flat: everything far from the border's colour."""


class GarmentColour(BaseModel):
    """One colour of the garment, in CIELAB (D65, 2 degree), with its share."""

    model_config = ConfigDict(extra="forbid")

    L: float
    a: float
    b: float
    C: float
    h: float
    hex: str = Field(..., description="The colour on an sRGB screen, clipped into gamut.")
    share: float = Field(..., description="Fraction of the measured garment pixels, 0..1.")


class GarmentResult(BaseModel):
    model_config = ConfigDict(extra="forbid")

    status: GarmentStatus
    reject_reason: GarmentRejectReason | None = None
    method: GarmentMethod | None = None
    coverage: float | None = Field(
        None, description="Share of the frame the garment mask covered."
    )
    colours: list[GarmentColour] = Field(
        default_factory=list, description="Largest share first. The first is the garment's colour."
    )
    algo_version: str = GARMENT_ALGO_VERSION

    def to_json(self, **kwargs: Any) -> str:
        return self.model_dump_json(**kwargs)


def analyze_garment(
    image_bytes: bytes,
    config: EngineConfig,
    segment: Any | None = None,
) -> GarmentResult:
    """Decode a product photo and measure its garment.

    ``segment`` maps an RGB array to the segmenter's category mask; ``None``
    (no models on this box) means every photo is treated as a flat shot.
    """
    garment_config = config.model_copy(update={"min_short_side": config.garment_min_short_side})

    try:
        image = load_image(image_bytes, garment_config)
    except ImageInputError as exc:
        return GarmentResult(
            status=GarmentStatus.REJECTED,
            reject_reason=GarmentRejectReason(exc.reject_reason.value),
        )

    category_mask = segment(image.rgb) if segment is not None else None

    return measure_garment(image.rgb, category_mask, config)


def measure_garment(
    rgb: ImageArray,
    category_mask: MaskArray | None,
    config: EngineConfig,
) -> GarmentResult:
    """Measure an already decoded photo. Pure: the testable half."""
    mask, method = _garment_mask(rgb, category_mask, config)
    coverage = float(np.count_nonzero(mask)) / float(mask.size)

    if coverage < config.garment_min_coverage:
        return GarmentResult(
            status=GarmentStatus.REJECTED,
            reject_reason=GarmentRejectReason.NO_GARMENT,
            method=method,
            coverage=round(coverage, 4),
        )

    lab = masked_lab(rgb, mask, config)
    low, high = np.percentile(
        lab[:, 0], [config.garment_l_percentile_low, config.garment_l_percentile_high]
    )
    lab = lab[(lab[:, 0] >= low) & (lab[:, 0] <= high)]

    return GarmentResult(
        status=GarmentStatus.OK,
        method=method,
        coverage=round(coverage, 4),
        colours=_colours(lab, config),
    )


# --- isolating the garment ------------------------------------------------


def _garment_mask(
    rgb: ImageArray, category_mask: MaskArray | None, config: EngineConfig
) -> tuple[MaskArray, GarmentMethod]:
    if category_mask is not None:
        total = float(category_mask.size)
        person = np.isin(
            category_mask,
            [SegmentClass.HAIR, SegmentClass.BODY_SKIN, SegmentClass.FACE_SKIN],
        )
        clothes = category_mask == SegmentClass.CLOTHES
        if (
            np.count_nonzero(person) / total >= config.garment_person_fraction
            and np.count_nonzero(clothes) / total >= config.garment_min_coverage
        ):
            return _clean(clothes.astype(np.uint8) * 255), GarmentMethod.SEGMENTER

    return _clean(_off_backdrop(rgb, config)), GarmentMethod.BACKGROUND


def _off_backdrop(rgb: ImageArray, config: EngineConfig) -> MaskArray:
    """Everything whose colour is far from the frame border's median colour."""
    height, width = rgb.shape[:2]
    band = max(2, int(round(min(height, width) * config.garment_border_fraction)))

    border = np.concatenate(
        [
            rgb[:band].reshape(-1, 3),
            rgb[-band:].reshape(-1, 3),
            rgb[:, :band].reshape(-1, 3),
            rgb[:, -band:].reshape(-1, 3),
        ]
    )
    backdrop = rgb_to_lab(np.median(border, axis=0))

    # CIE76 over the whole frame: a threshold, not a measurement, so the plain
    # Euclidean distance is enough and a fraction of the cost of CIEDE2000.
    distance = np.linalg.norm(rgb_to_lab(rgb) - backdrop, axis=2)
    return np.asarray((distance > config.garment_background_delta_e) * 255, dtype=np.uint8)


def _clean(mask: MaskArray) -> MaskArray:
    """Drop specks and seams: an opening removes isolated pixels (sensor noise,
    a dust spot on the backdrop) without eating into the garment's outline."""
    kernel = np.ones((3, 3), dtype=np.uint8)
    return np.asarray(cv2.morphologyEx(mask, cv2.MORPH_OPEN, kernel), dtype=np.uint8)


# --- naming its colours -----------------------------------------------------


def _colours(lab: FloatArray, config: EngineConfig) -> list[GarmentColour]:
    if lab.shape[0] == 0:
        return []

    k = min(config.garment_clusters, lab.shape[0])
    cv2.setRNGSeed(config.sampling_seed)
    criteria = (cv2.TERM_CRITERIA_EPS + cv2.TERM_CRITERIA_MAX_ITER, 30, 0.5)
    _, labels, _ = cv2.kmeans(
        lab.astype(np.float32), k, None, criteria, 3, cv2.KMEANS_PP_CENTERS
    )
    labels = labels.ravel()

    # Each cluster as its median and its size; the median, not the k-means
    # centre, so one cluster's stray tail does not drag its colour.
    clusters = [
        (np.median(lab[labels == index], axis=0), int(np.count_nonzero(labels == index)))
        for index in range(k)
        if np.any(labels == index)
    ]
    clusters = _merge(clusters, config)

    total = float(sum(size for _, size in clusters))
    colours = [
        _colour(centre, size / total)
        for centre, size in sorted(clusters, key=lambda cluster: -cluster[1])
        if size / total >= config.garment_min_share
    ]
    return colours


def _merge(
    clusters: list[tuple[FloatArray, int]], config: EngineConfig
) -> list[tuple[FloatArray, int]]:
    """Fold together clusters that are one colour in light and in shade.

    Shading a dyed fabric moves its lightness and chroma a long way and its hue
    hardly at all, so two chromatic clusters are one colour when their hues
    agree - a red top in folds is still one red, not four. Two near-neutral
    clusters have no hue worth the name and are compared on distance instead,
    with lightness counted at half weight for the same reason.
    """
    clusters = list(clusters)
    while len(clusters) > 1:
        best: tuple[float, int, int] | None = None
        for i in range(len(clusters)):
            for j in range(i + 1, len(clusters)):
                distance = _shading_distance(clusters[i][0], clusters[j][0])
                if _same_colour(clusters[i][0], clusters[j][0], config) and (
                    best is None or distance < best[0]
                ):
                    best = (distance, i, j)
        if best is None:
            break
        _, i, j = best
        (a, size_a), (b, size_b) = clusters[i], clusters[j]
        merged = (a * size_a + b * size_b) / (size_a + size_b)
        clusters[i] = (merged, size_a + size_b)
        del clusters[j]
    return clusters


def _shading_distance(first: FloatArray, second: FloatArray) -> float:
    """CIE76 with lightness at half weight."""
    delta = first - second
    return float(np.sqrt((delta[0] * 0.5) ** 2 + delta[1] ** 2 + delta[2] ** 2))


def _same_colour(first: FloatArray, second: FloatArray, config: EngineConfig) -> bool:
    chroma_first = float(np.hypot(first[1], first[2]))
    chroma_second = float(np.hypot(second[1], second[2]))

    if min(chroma_first, chroma_second) >= config.garment_chromatic_c:
        hue_first = np.degrees(np.arctan2(first[2], first[1]))
        hue_second = np.degrees(np.arctan2(second[2], second[1]))
        difference = abs((hue_first - hue_second + 180.0) % 360.0 - 180.0)
        return bool(difference <= config.garment_merge_hue_degrees)

    return _shading_distance(first, second) < config.garment_merge_delta_e


def _colour(lab: FloatArray, share: float) -> GarmentColour:
    lightness, a, b = (float(value) for value in lab)
    rgb = np.clip(colour.XYZ_to_sRGB(colour.Lab_to_XYZ(lab)), 0.0, 1.0)
    hex_value = "#{:02X}{:02X}{:02X}".format(*(int(round(channel * 255)) for channel in rgb))
    return GarmentColour(
        L=round(lightness, 3),
        a=round(a, 3),
        b=round(b, 3),
        C=round(float(np.hypot(a, b)), 3),
        h=round(float(np.degrees(np.arctan2(b, a)) % 360.0), 3),
        hex=hex_value,
        share=round(share, 4),
    )
