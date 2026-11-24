"""Debug artifacts.

Collected only when ``analyze(debug=True)``. Nothing here is written to disk or
logged by the library - the CLI ``report`` command is the single place where
these ever leave memory, and it writes into a gitignored directory. This matters:
the artifacts contain a face photo, and the product operates under UAE PDPL.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from enum import Enum
from typing import Any

import cv2
import numpy as np

from .arrays import ImageArray, MaskArray, PointArray
from .zones import LANDMARKS_IN_USE, Zones, ZoneSpec


class LandmarkLabels(str, Enum):
    """Which landmark indices to print on the overlay.

    This is the tool for correcting the polygons: with numbers on the face you
    can read off which index sits where and edit the constant in ``zones.py``.
    """

    NONE = "none"
    ZONES = "zones"
    """Only the points some zone actually depends on."""

    ALL = "all"
    """All 478. Dense, but it is how you find a better point than the one in use."""


# Overlay colours, RGB. Deliberately saturated and distinct - this is a
# diagnostic image, not product UI, so the site palette does not apply.
ZONE_COLORS: dict[str, tuple[int, int, int]] = {
    "right_cheek": (255, 64, 64),
    "left_cheek": (255, 160, 32),
    "forehead": (64, 200, 255),
    "hair": (168, 96, 255),
    "iris_right": (32, 255, 128),
    "iris_left": (0, 200, 96),
    "sclera_right": (255, 255, 64),
    "sclera_left": (220, 220, 0),
}

OVERLAY_ALPHA = 0.45

# How far a pixel that did NOT make it into the sample is pushed back.
SAMPLED_FADE = 0.35


@dataclass
class DebugArtifacts:
    """Everything worth looking at when a result is surprising."""

    source_color_space: str | None = None
    source_format: str | None = None
    original_size: tuple[int, int] | None = None
    downscaled: bool = False

    converted_rgb: ImageArray | None = None
    """The image as measured: after EXIF, ICC conversion and downscaling."""

    landmarks: PointArray | None = None
    face_bbox: tuple[float, float, float, float] | None = None
    face_count: int = 0
    head_pose: dict[str, float | None] = field(default_factory=dict)
    head_pose_source: str | None = None

    category_mask: MaskArray | None = None
    zone_masks: dict[str, MaskArray] = field(default_factory=dict)
    zone_polygons: dict[str, PointArray] = field(default_factory=dict)
    zone_pixels: dict[str, int] = field(default_factory=dict)
    zone_specs: dict[str, ZoneSpec] = field(default_factory=dict)
    insufficient_zones: list[str] = field(default_factory=list)
    insufficient_reasons: dict[str, str] = field(default_factory=dict)
    """Why each one is insufficient. "insufficient" on its own tells the
    reviewer nothing they can act on."""

    corrected_rgb: ImageArray | None = None
    """The frame after chromatic adaptation. Preview only - nothing is measured
    off it - and it exists so a human can see what the correction did."""

    white_balance: dict[str, Any] = field(default_factory=dict)
    """What the white-balance step decided and why, including the estimates it
    rejected. Not in the contract: the contract carries the verdict, this
    carries the argument."""

    uncorrected_samples: Any = None
    """The samples as measured, before adaptation. The contract carries the
    corrected ones; this is what makes a before/after possible."""

    quality: dict[str, Any] = field(default_factory=dict)
    """What the quality gate decided and on which measurements. A rejection the
    reviewer cannot audit is a rejection nobody can fix."""

    confidence_factor: float = 1.0
    """Everything this pipeline knows that should lower the confidence stage 3
    will publish, multiplied together. Debug only - no stage before the
    classifier has any business naming a confidence."""

    classifier: dict[str, Any] = field(default_factory=dict)
    """How the season was arrived at: the score of all 12, the distances behind
    them, every feature that was excluded with its reason, and the confidence
    taken apart into its multipliers. The contract carries two seasons and one
    number; this is the working that produced them, and without it a confidence
    of 0.31 is an assertion rather than a result."""

    sampled_masks: dict[str, MaskArray] = field(default_factory=dict)
    """Per zone, the pixels that survived EVERY filter - not the zone's mask,
    which is only where we looked. A zone can look perfectly placed on the
    overlay and still have had three quarters of itself thrown away by a trim
    nobody can see."""

    sclera_quality: dict[str, Any] = field(default_factory=dict)
    """How much of the sclera mask survived selection, and whether to trust it.
    Not in the contract - ``white_balance`` reads it to decide whether the sclera
    can serve as the white reference."""

    sclera_sides: list[dict[str, Any]] = field(default_factory=list)
    """The same, per eye. Two eyes agreeing is the evidence the pooled number
    cannot show on its own."""

    notes: list[str] = field(default_factory=list)

    def record_zones(self, zones: Zones) -> None:
        for name, zone in zones.zones.items():
            self.zone_pixels[name] = zone.pixels
            if zone.spec is not None:
                self.zone_specs[name] = zone.spec
            if name in ZONE_COLORS:
                self.zone_masks[name] = zone.mask
            if zone.polygon is not None:
                self.zone_polygons[name] = zone.polygon
        self.insufficient_zones = zones.insufficient
        self.insufficient_reasons = zones.reasons

    @property
    def face_area(self) -> float:
        """Face bounding-box area in pixels, the denominator for zone shares."""
        if self.face_bbox is None:
            return 0.0
        return float(self.face_bbox[2] * self.face_bbox[3])


def render_overlay(
    rgb: ImageArray,
    debug: DebugArtifacts,
    landmark_labels: LandmarkLabels = LandmarkLabels.NONE,
    *,
    draw_bbox: bool = True,
) -> ImageArray:
    """Draw zone masks and polygons over the image, semi-transparently."""
    canvas = rgb.astype(np.float32).copy()

    for name, mask in debug.zone_masks.items():
        colour = np.asarray(ZONE_COLORS.get(name, (255, 255, 255)), dtype=np.float32)
        selected = mask > 0
        if not selected.any():
            continue
        canvas[selected] = canvas[selected] * (1.0 - OVERLAY_ALPHA) + colour * OVERLAY_ALPHA

    out: ImageArray = np.clip(canvas, 0, 255).astype(np.uint8)

    # Polygon outlines on top, fully opaque: these are the landmark picks we
    # actually need to judge by eye.
    for name, polygon in debug.zone_polygons.items():
        colour_t = ZONE_COLORS.get(name, (255, 255, 255))
        cv2.polylines(
            out,
            [np.round(polygon).astype(np.int32).reshape(-1, 1, 2)],
            isClosed=True,
            color=tuple(int(c) for c in colour_t),
            thickness=max(1, out.shape[1] // 500),
        )

    if draw_bbox and debug.face_bbox is not None and debug.face_bbox[2] > 0:
        x, y, w, h = (round(v) for v in debug.face_bbox)
        cv2.rectangle(out, (x, y), (x + w, y + h), (255, 255, 255), 1)

    if landmark_labels is not LandmarkLabels.NONE:
        draw_landmark_labels(out, debug, landmark_labels)

    return out


def draw_landmark_labels(image: ImageArray, debug: DebugArtifacts, which: LandmarkLabels) -> None:
    """Print landmark indices onto the image, in place.

    Drawn small, black-outlined then white-filled, so the number stays readable
    over hair, skin and background alike.
    """
    points = debug.landmarks
    if points is None or len(points) == 0 or which is LandmarkLabels.NONE:
        return

    indices = (
        range(len(points))
        if which is LandmarkLabels.ALL
        else sorted(i for i in LANDMARKS_IN_USE if i < len(points))
    )
    # Scale with the face, not the image: a crop and a full frame must label
    # legibly at the same apparent size.
    reference = debug.face_bbox[2] if debug.face_bbox else float(image.shape[1])
    scale = max(0.22, min(0.5, reference / 1400.0))
    dot = max(1, round(reference / 400.0))

    for index in indices:
        x, y = (round(float(v)) for v in points[index])
        cv2.circle(image, (x, y), dot, (255, 255, 255), thickness=-1)
        cv2.circle(image, (x, y), dot, (0, 0, 0), thickness=1)
        origin = (x + dot + 1, y - dot - 1)
        for colour, thickness in (((0, 0, 0), 3), ((255, 255, 255), 1)):
            cv2.putText(
                image,
                str(index),
                origin,
                cv2.FONT_HERSHEY_SIMPLEX,
                scale,
                colour,
                thickness,
                cv2.LINE_AA,
            )


def render_outlines(
    rgb: ImageArray,
    debug: DebugArtifacts,
    landmark_labels: LandmarkLabels = LandmarkLabels.NONE,
) -> ImageArray:
    """The zones as outlines over the untouched image.

    The filled overlay is right for judging placement at a glance and wrong for
    everything else: it paints over the pixels, so a close-up of an eye shows
    the colour we chose for the zone rather than the colour of the eye. Here the
    image is left alone and only the boundary is drawn.
    """
    out: ImageArray = rgb.copy()
    thickness = max(1, out.shape[1] // 600)

    for name, mask in debug.zone_masks.items():
        colour = tuple(int(c) for c in ZONE_COLORS.get(name, (255, 255, 255)))
        contours, _ = cv2.findContours(
            (mask > 0).astype(np.uint8), cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE
        )
        cv2.drawContours(out, contours, -1, colour, thickness)

    if landmark_labels is not LandmarkLabels.NONE:
        draw_landmark_labels(out, debug, landmark_labels)

    return out


def render_sampled(rgb: ImageArray, debug: DebugArtifacts, zone: str) -> ImageArray | None:
    """The frame with everything but one zone's SAMPLED pixels pushed back.

    Not the zone's mask - the pixels that survived every filter. The difference
    is the whole point: "the mask is on the right place" and "the measurement
    came from these pixels" are different claims, and only the second one
    explains a number.

    Rejected pixels are darkened and desaturated rather than blanked, so the
    anatomy stays readable and a reviewer can see what was excluded and judge
    whether it should have been.
    """
    kept = debug.sampled_masks.get(zone)
    if kept is None or not kept.any():
        return None

    faded = rgb.astype(np.float32)
    grey = faded @ np.asarray([0.2126, 0.7152, 0.0722], dtype=np.float32)
    faded = np.repeat(grey[:, :, None], 3, axis=2) * SAMPLED_FADE

    out: ImageArray = np.clip(faded, 0, 255).astype(np.uint8)
    selected = kept > 0
    out[selected] = rgb[selected]

    return out


def render_zone_crop(
    overlaid: ImageArray,
    debug: DebugArtifacts,
    zone: str,
    *,
    pad: float = 0.9,
    min_size: int = 240,
) -> ImageArray | None:
    """A zoomed window of an already-overlaid frame, centred on one zone.

    Mask defects around an eye are invisible at full-frame scale - this is the
    view that shows whether the sclera mask is on the white of the eye or on the
    lash line. Takes the rendered overlay rather than rendering its own, so a
    report with nine crops still only composites the frame once. Returns None
    when the zone produced no pixels.
    """
    mask = debug.zone_masks.get(zone)
    if mask is None or not mask.any():
        return None

    ys, xs = np.nonzero(mask)
    height, width = overlaid.shape[:2]
    cx, cy = (float(xs.min() + xs.max()) / 2.0, float(ys.min() + ys.max()) / 2.0)
    half = max(float(xs.max() - xs.min()), float(ys.max() - ys.min())) * (0.5 + pad)
    half = max(half, 24.0)

    x0, x1 = max(0, round(cx - half)), min(width, round(cx + half))
    y0, y1 = max(0, round(cy - half)), min(height, round(cy + half))
    if x1 - x0 < 2 or y1 - y0 < 2:  # pragma: no cover - degenerate
        return None

    crop = np.ascontiguousarray(overlaid[y0:y1, x0:x1])

    if min(crop.shape[:2]) < min_size:
        factor = min_size / float(min(crop.shape[:2]))
        crop = np.asarray(
            cv2.resize(
                crop,
                (max(1, round(crop.shape[1] * factor)), max(1, round(crop.shape[0] * factor))),
                interpolation=cv2.INTER_NEAREST,  # keep mask edges crisp
            ),
            dtype=np.uint8,
        )
    return crop


def render_segmentation(debug: DebugArtifacts) -> ImageArray | None:
    """False-colour view of the segmenter's category mask."""
    if debug.category_mask is None:
        return None
    palette = np.asarray(
        [
            (30, 30, 30),  # background
            (168, 96, 255),  # hair
            (255, 160, 32),  # body-skin
            (255, 64, 64),  # face-skin
            (64, 200, 255),  # clothes
            (200, 200, 200),  # others
        ],
        dtype=np.uint8,
    )
    mask = np.clip(debug.category_mask, 0, len(palette) - 1)
    return np.asarray(palette[mask], dtype=np.uint8)
