"""Building the single-file HTML debug report.

The report exists for one reason: the landmark index sets in ``zones`` are a
first pass, and the only way to judge them is to look at the polygons drawn on
real faces. So it shows, per zone, the constant name and the exact indices it
came from, the resulting mask area, a zoomed crop with the mask on it, and the
measured colour. Everything is inlined as base64 so the file can be opened from
anywhere, and the CLI writes it into the gitignored ``reports/`` tree.
"""

from __future__ import annotations

import base64
import io
import json
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

import colour
import numpy as np
from jinja2 import Environment, FileSystemLoader, select_autoescape
from PIL import Image

from ..arrays import ImageArray
from ..debug import ZONE_COLORS, LandmarkLabels, render_segmentation
from ..images import OVERLAY, debug_images
from ..models import AnalysisResult, LabSample, Samples
from .stability import TRACKED_ZONES, PersonStability, delta_e

TEMPLATE_DIR = Path(__file__).resolve().parent
PREVIEW_WIDTH = 520
CROP_WIDTH = 300

# Order the zone table is printed in: measured sub-zones first, then the
# aggregates they feed.
ZONE_ORDER = (
    "right_cheek",
    "left_cheek",
    "forehead",
    "skin",
    "hair",
    "iris_right",
    "iris_left",
    "iris",
    "sclera_right",
    "sclera_left",
    "sclera",
)

# Captions for the crops ``images.debug_images`` renders, by its own image name.
# The zone each one comes from is that module's business, not the report's.
CROP_CAPTIONS: dict[str, str] = {
    "crop_right_cheek": "Right cheek (subject's)",
    "crop_left_cheek": "Left cheek (subject's)",
    "crop_forehead": "Forehead",
    "crop_right_eye": "Right eye",
    "crop_left_eye": "Left eye",
}


@dataclass
class ZoneRow:
    name: str
    constant: str | None
    indices: str
    point_count: int
    note: str
    pixels: int
    face_pct: float | None
    sample: LabSample | None
    swatch: str | None
    color: str
    insufficient: bool
    insufficient_reason: str | None


@dataclass
class CorrectionRow:
    """One zone, before and after the illuminant was taken out."""

    name: str
    before: LabSample | None
    after: LabSample | None
    swatch_before: str | None
    swatch_after: str | None
    delta_e: float | None


@dataclass
class ReportEntry:
    filename: str
    status: str
    reject_reason: str | None
    source_color_space: str | None
    source_format: str | None
    original_size: str | None
    measured_size: str | None
    downscaled: bool
    face_count: int
    head_pose: dict[str, Any]
    head_pose_source: str | None
    timings: dict[str, float]
    total_ms: float
    zone_rows: list[ZoneRow] = field(default_factory=list)
    images: dict[str, str] = field(default_factory=dict)
    crops: list[tuple[str, str]] = field(default_factory=list)
    sclera_quality: dict[str, Any] = field(default_factory=dict)
    sclera_sides: list[dict[str, Any]] = field(default_factory=list)
    white_balance: dict[str, Any] = field(default_factory=dict)
    quality: dict[str, Any] = field(default_factory=dict)
    confidence_factor: float = 1.0
    correction_rows: list[CorrectionRow] = field(default_factory=list)
    error: str | None = None


def build_entry(
    filename: str,
    source_bytes: bytes,
    result: AnalysisResult,
    landmark_labels: LandmarkLabels = LandmarkLabels.NONE,
) -> ReportEntry:
    """Assemble one photo's section from its analysis result."""
    debug = result.debug
    timings = result.timings_ms
    entry = ReportEntry(
        filename=filename,
        status=result.status.value,
        reject_reason=result.reject_reason.value if result.reject_reason else None,
        source_color_space=result.source_color_space,
        source_format=getattr(debug, "source_format", None),
        original_size=_size(getattr(debug, "original_size", None)),
        measured_size=None,
        downscaled=bool(getattr(debug, "downscaled", False)),
        face_count=int(getattr(debug, "face_count", 0) or 0),
        head_pose=dict(getattr(debug, "head_pose", {}) or {}),
        head_pose_source=getattr(debug, "head_pose_source", None),
        timings=timings,
        total_ms=round(sum(timings.values()), 2),
    )

    entry.images["original"] = _encode_source(source_bytes)

    if debug is None:
        return entry

    converted = debug.converted_rgb
    if converted is not None:
        entry.measured_size = f"{converted.shape[1]}x{converted.shape[0]}"
        entry.images["converted"] = _encode_array(converted)
    corrected = getattr(debug, "corrected_rgb", None)
    if corrected is not None:
        entry.images["corrected"] = _encode_array(corrected)

    # The overlay and the crops come from the public renderer, so the report and
    # anything else serving these pictures draw them the same way. Only the
    # encoding differs: the report re-sizes to JPEG to keep one self-contained
    # HTML file down to a sane weight.
    rendered = debug_images(result, debug, landmark_labels)
    if OVERLAY in rendered:
        entry.images["overlay"] = _encode_array(rendered[OVERLAY])
    entry.crops = [
        (CROP_CAPTIONS[name], _encode_array(image, width=CROP_WIDTH))
        for name, image in rendered.items()
        if name in CROP_CAPTIONS
    ]

    segmentation = render_segmentation(debug)
    if segmentation is not None:
        entry.images["segmentation"] = _encode_array(segmentation)

    entry.zone_rows = _zone_rows(result, debug)
    entry.sclera_quality = dict(getattr(debug, "sclera_quality", {}) or {})
    entry.sclera_sides = list(getattr(debug, "sclera_sides", []) or [])
    entry.white_balance = dict(getattr(debug, "white_balance", {}) or {})
    entry.quality = dict(getattr(debug, "quality", {}) or {})
    entry.confidence_factor = float(getattr(debug, "confidence_factor", 1.0) or 1.0)
    entry.correction_rows = _correction_rows(getattr(debug, "uncorrected_samples", None), result)
    return entry


def build_report(
    entries: list[ReportEntry],
    out_dir: Path,
    title: str,
    people: list[PersonStability] | None = None,
    summary: dict[str, Any] | None = None,
) -> Path:
    """Write the report, and - for a --by-person run - ``summary.json`` beside it.

    The JSON is the point of the exercise: re-run the same set after stage 2 and
    the two summaries are directly comparable.
    """
    out_dir.mkdir(parents=True, exist_ok=True)
    environment = Environment(
        loader=FileSystemLoader(str(TEMPLATE_DIR)),
        autoescape=select_autoescape(["html"]),
    )
    template = environment.get_template("report.html.j2")
    html = template.render(
        entries=entries,
        title=title,
        zone_colors=ZONE_COLORS,
        people=people or [],
        summary=summary,
        tracked_zones=TRACKED_ZONES,
    )
    destination = out_dir / "index.html"
    destination.write_text(html, encoding="utf-8")

    if summary is not None:
        (out_dir / "summary.json").write_text(
            json.dumps(summary, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
        )
    return destination


# --- helpers ---------------------------------------------------------------


def _zone_rows(result: AnalysisResult, debug: Any) -> list[ZoneRow]:
    samples = result.samples
    by_name: dict[str, LabSample | None] = {
        "skin": samples.skin,
        "right_cheek": samples.skin_parts.right_cheek,
        "left_cheek": samples.skin_parts.left_cheek,
        "forehead": samples.skin_parts.forehead,
        "hair": samples.hair,
        "iris": samples.iris,
        "sclera": samples.sclera,
    }
    pixels = dict(getattr(debug, "zone_pixels", {}) or {})
    specs = dict(getattr(debug, "zone_specs", {}) or {})
    insufficient = set(getattr(debug, "insufficient_zones", []) or [])
    why = dict(getattr(debug, "insufficient_reasons", {}) or {})
    face_area = float(getattr(debug, "face_area", 0.0) or 0.0)

    rows: list[ZoneRow] = []
    for name in ZONE_ORDER:
        if name not in pixels and name not in by_name:
            continue
        spec = specs.get(name)
        count = pixels.get(name, 0)
        sample = by_name.get(name)
        rows.append(
            ZoneRow(
                name=name,
                constant=spec.constant if spec else None,
                indices=", ".join(str(i) for i in spec.indices) if spec and spec.indices else "",
                point_count=len(spec.indices) if spec else 0,
                note=spec.note if spec else "",
                pixels=count,
                face_pct=round(100.0 * count / face_area, 2) if face_area > 0 else None,
                sample=sample,
                swatch=_swatch(sample),
                color=_css_color(ZONE_COLORS.get(name, (110, 110, 110))),
                insufficient=name in insufficient,
                insufficient_reason=why.get(name),
            )
        )
    return rows


def _correction_rows(before: Any, result: AnalysisResult) -> list[CorrectionRow]:
    """Before and after, per zone.

    Only built when the debug artifacts carried the uncorrected samples, which
    is the only place they exist - the contract carries the corrected ones, and
    a before/after is the one thing that makes an illuminant estimate checkable
    by eye.
    """
    if not isinstance(before, Samples):
        return []
    rows: list[CorrectionRow] = []
    for name, was, now in (
        ("skin", before.skin, result.samples.skin),
        ("hair", before.hair, result.samples.hair),
        ("iris", before.iris, result.samples.iris),
        ("sclera", before.sclera, result.samples.sclera),
    ):
        if was is None and now is None:
            continue
        rows.append(
            CorrectionRow(
                name=name,
                before=was,
                after=now,
                swatch_before=_swatch(was),
                swatch_after=_swatch(now),
                delta_e=delta_e(was, now),
            )
        )
    return rows


def _swatch(sample: LabSample | None) -> str | None:
    """Render a measured CIELAB value back to an sRGB hex for the eye to check."""
    if sample is None:
        return None
    lab = np.asarray([sample.L, sample.a, sample.b], dtype=np.float64)
    rgb = colour.XYZ_to_sRGB(colour.Lab_to_XYZ(lab))
    clipped = np.clip(rgb, 0.0, 1.0) * 255.0
    red, green, blue = (round(float(c)) for c in clipped)
    return f"#{red:02x}{green:02x}{blue:02x}"


def _css_color(rgb: tuple[int, int, int]) -> str:
    return f"#{rgb[0]:02x}{rgb[1]:02x}{rgb[2]:02x}"


def _size(size: tuple[int, int] | None) -> str | None:
    return None if size is None else f"{size[0]}x{size[1]}"


def _encode_array(rgb: ImageArray, width: int = PREVIEW_WIDTH) -> str:
    return _encode_image(Image.fromarray(np.ascontiguousarray(rgb)), width)


def _encode_source(data: bytes) -> str:
    try:
        with Image.open(io.BytesIO(data)) as img:
            return _encode_image(img.convert("RGB"), PREVIEW_WIDTH)
    except (OSError, ValueError):
        return ""


def _encode_image(img: Image.Image, width: int) -> str:
    if img.width > width:
        height = max(1, round(img.height * width / img.width))
        img = img.resize((width, height), Image.Resampling.LANCZOS)
    buffer = io.BytesIO()
    img.save(buffer, format="JPEG", quality=85)
    return "data:image/jpeg;base64," + base64.b64encode(buffer.getvalue()).decode("ascii")
