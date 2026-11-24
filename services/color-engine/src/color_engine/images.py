"""Debug images as a public API.

Until now the overlay and the zone close-ups only existed inside the HTML report
builder, so anything else that wanted them - pose-service serving them over HTTP,
for one - had to reach into ``report.builder`` and copy its encoding. That would
have made a private module part of two contracts at once.

This module is the seam. :func:`debug_images` renders the arrays,
:func:`render_debug_images` hands them back as PNG bytes, and the report builder
calls the same :func:`debug_images` and does its own JPEG sizing on the result -
one rendering path, two ways of encoding it.

The drawing itself is not reimplemented here: ``debug.render_overlay`` and
``debug.render_zone_crop`` do it, exactly as they did for the report.

Privacy: everything here is a picture of a face. Nothing in this module writes
to disk or logs, and the caller owns what happens to the bytes.
"""

from __future__ import annotations

import io

import numpy as np
from PIL import Image

from .arrays import ImageArray
from .debug import (
    DebugArtifacts,
    LandmarkLabels,
    render_outlines,
    render_overlay,
    render_sampled,
    render_zone_crop,
)
from .models import AnalysisResult

OVERLAY = "overlay"
WB_BEFORE_AFTER = "wb_before_after"

SOURCE_PREVIEW = "source_preview"
"""The uploaded photo, decoded - and the only view that exists for a browser
rather than for a reviewer.

A phone sends HEIC. No browser renders it, so the admin page showed a broken
image where "As uploaded" should be. The decode has to happen somewhere, and it
happens here because here is where it has ALREADY happened: this is the frame
after EXIF rotation and the ICC conversion to sRGB, which is to say the pixels
the engine actually measured. Laravel has no HEIC decoder and would need a heavy
one added to get a worse answer - a thumbnail of the file rather than a picture
of what was measured."""

CROP_IMAGES: tuple[tuple[str, str], ...] = (
    ("crop_left_eye", "iris_left"),
    ("crop_right_eye", "iris_right"),
    ("crop_left_cheek", "left_cheek"),
    ("crop_right_cheek", "right_cheek"),
    ("crop_forehead", "forehead"),
)
"""``(image name, zone name)``. Left and right are the **subject's**, as
everywhere else in this package - the subject's left eye is on the right of a
frontal photo."""

SAMPLED_PREFIX = "sampled_"

SAMPLED_ZONES: tuple[str, ...] = (
    "skin",
    "left_cheek",
    "right_cheek",
    "forehead",
    "hair",
    "iris",
    "sclera",
)
"""Zones that get a "what was actually measured" view. Per zone rather than one
picture of everything, because the question is always about one number."""

IMAGE_NAMES: tuple[str, ...] = (
    SOURCE_PREVIEW,
    OVERLAY,
    *(name for name, _ in CROP_IMAGES),
    *(SAMPLED_PREFIX + zone for zone in SAMPLED_ZONES),
    WB_BEFORE_AFTER,
)
"""Every name this module can produce. A name is absent from the result when the
photo did not carry it - a rejected photo has no zones, and an uncorrected one
has no before/after."""

SEPARATOR_PX = 8
SEPARATOR_VALUE = 255


def debug_images(
    result: AnalysisResult,
    debug: DebugArtifacts | None,
    landmark_labels: LandmarkLabels = LandmarkLabels.NONE,
) -> dict[str, ImageArray]:
    """Render the debug views as RGB arrays.

    Returns only what the photo actually produced. A photo rejected before the
    zones were built has no overlay and no crops; a photo white balance did not
    correct has no before/after. Callers must not assume a key is there.
    """
    del result  # the debug artifacts carry everything the pictures need
    if debug is None or debug.converted_rgb is None:
        return {}

    images: dict[str, ImageArray] = {SOURCE_PREVIEW: debug.converted_rgb}

    if debug.zone_masks or debug.landmarks is not None:
        images[OVERLAY] = render_overlay(debug.converted_rgb, debug, landmark_labels)

        # The crops come off an OUTLINED frame, not the filled overlay. A filled
        # one paints over the pixels, so a close-up of an eye would show the
        # colour we chose for the zone rather than the colour of the eye - which
        # is the one thing a close-up of an eye exists to show.
        outlined = render_outlines(debug.converted_rgb, debug, landmark_labels)
        for name, zone in CROP_IMAGES:
            crop = render_zone_crop(outlined, debug, zone)
            if crop is not None:
                images[name] = crop

    # What was actually MEASURED, as opposed to where we looked. A zone can sit
    # perfectly on the overlay and still have had three quarters of itself
    # thrown away by a trim nobody can see.
    for zone in SAMPLED_ZONES:
        sampled = render_sampled(debug.converted_rgb, debug, zone)
        if sampled is None:
            continue
        crop = render_zone_crop(sampled, debug, zone) if zone in debug.zone_masks else None
        images[SAMPLED_PREFIX + zone] = crop if crop is not None else sampled

    before_after = _before_after(debug)
    if before_after is not None:
        images[WB_BEFORE_AFTER] = before_after

    return images


def render_debug_images(
    result: AnalysisResult,
    debug: DebugArtifacts | None,
    landmark_labels: LandmarkLabels = LandmarkLabels.NONE,
) -> dict[str, bytes]:
    """The debug views as PNG bytes, ready to be served or stored.

    PNG, not JPEG: these are diagnostic pictures whose whole job is to show
    exactly which pixels a mask covered, and JPEG's ringing around a hard mask
    edge is precisely the artefact that would be mistaken for a mask defect.
    """
    return {
        name: _encode_png(image)
        for name, image in debug_images(result, debug, landmark_labels).items()
    }


def _before_after(debug: DebugArtifacts) -> ImageArray | None:
    """The frame as measured beside the frame adapted to D65, in one image.

    Side by side rather than two images, because the question it answers - did
    the correction do something sane - is only answerable by comparing them, and
    two separate files let a viewer look at one without the other.
    """
    before = debug.converted_rgb
    after = getattr(debug, "corrected_rgb", None)
    if before is None or after is None or before.shape != after.shape:
        return None

    height = before.shape[0]
    separator = np.full((height, SEPARATOR_PX, 3), SEPARATOR_VALUE, dtype=np.uint8)
    return np.asarray(np.hstack([before, separator, after]), dtype=np.uint8)


def _encode_png(rgb: ImageArray) -> bytes:
    buffer = io.BytesIO()
    Image.fromarray(np.ascontiguousarray(rgb)).save(buffer, format="PNG", optimize=True)
    return buffer.getvalue()
