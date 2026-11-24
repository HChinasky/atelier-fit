"""Bounding-box confidence trimming and visible-vs-extrapolated distinguishability.

A waist-up crop of a full-body photo makes MediaPipe extrapolate the legs
off-frame with ~0 confidence. The bbox must reflect only the visible body, while
the extrapolated leg landmarks must still be present with honestly low
confidence so the caller (Laravel, step 9.2) can tell them apart.
"""
from __future__ import annotations

import io

from PIL import Image

from conftest import FULL_BODY, read_bytes

LOWER_BODY = {"left_knee", "right_knee", "left_ankle", "right_ankle"}


def _post(client, data: bytes, filename="photo.jpg"):
    return client.post("/pose", files={"image": (filename, data, "image/jpeg")})


def _by_name(person):
    return {lm["name"]: lm for lm in person["landmarks"]}


def _waist_up_crop(path) -> bytes:
    """A waist-up subject placed at the top of a TALL canvas.

    The person occupies only the upper ~45%; the rest is empty background. This
    is the case that matters: MediaPipe extrapolates the legs *downwards into the
    canvas* (in-frame, low confidence), so clamping to the image does NOT trim
    them — only the confidence filter does.
    """
    img = Image.open(io.BytesIO(read_bytes(path))).convert("RGB")
    crop = img.crop((0, 0, img.width, int(img.height * 0.45)))
    canvas = Image.new("RGB", (img.width, img.height), (255, 255, 255))
    canvas.paste(crop, (0, 0))
    buf = io.BytesIO()
    canvas.save(buf, format="JPEG")
    return buf.getvalue()


def test_full_body_bbox_includes_visible_legs(client):
    resp = _post(client, read_bytes(FULL_BODY))
    assert resp.status_code == 200
    body = resp.json()
    assert body["person_count"] == 1
    assert body["bbox_confidence_threshold"] == 0.5

    person = body["main_person"]
    names = _by_name(person)
    dims = body["image_dimensions"]

    # Legs are genuinely visible -> high confidence, and contribute to the bbox:
    # the box must reach down near the ankles.
    for name in LOWER_BODY:
        assert names[name]["confidence"] > 0.5
    bbox = person["bounding_box"]
    box_bottom = bbox["y"] + bbox["height"]
    lowest_ankle = max(names["left_ankle"]["y"], names["right_ankle"]["y"])
    # bbox bottom should be at/below the ankles (within a small margin).
    assert box_bottom >= lowest_ankle - 5
    # And the box should span most of the (tall) image height.
    assert bbox["height"] > 0.6 * dims["height"]


def test_waist_up_bbox_excludes_extrapolated_legs(client):
    resp = _post(client, _waist_up_crop(FULL_BODY))
    assert resp.status_code == 200
    body = resp.json()
    assert body["person_count"] == 1

    person = body["main_person"]
    names = _by_name(person)
    dims = body["image_dimensions"]
    bbox = person["bounding_box"]

    # Legs are off the visible body -> extrapolated with low confidence.
    assert names["left_ankle"]["confidence"] < 0.5
    assert names["right_ankle"]["confidence"] < 0.5

    # The extrapolated ankles land *inside* the tall canvas (in-frame), so image
    # clamping alone would not exclude them...
    assert names["left_ankle"]["y"] < dims["height"]
    # ...yet the bbox bottom stays above the extrapolated ankles: the confidence
    # filter — not clamping — is what keeps the box tight to the visible body.
    box_bottom = bbox["y"] + bbox["height"]
    assert box_bottom < names["left_ankle"]["y"]
    assert box_bottom < names["left_foot_index"]["y"]
    # Sanity: the box is genuinely interior to the canvas, not clamp-limited.
    assert box_bottom < dims["height"] - 50


def test_visible_vs_extrapolated_is_distinguishable(client):
    """The same body point reads high-confidence when visible, low when not."""
    full = _by_name(_post(client, read_bytes(FULL_BODY)).json()["main_person"])
    waist = _by_name(_post(client, _waist_up_crop(FULL_BODY)).json()["main_person"])

    # Visible ankles: confident. Off-frame ankles: not.
    assert full["left_ankle"]["confidence"] > 0.5
    assert waist["left_ankle"]["confidence"] < 0.5
    # Shoulders are visible in both -> confident in both (sanity check).
    assert full["left_shoulder"]["confidence"] > 0.5
    assert waist["left_shoulder"]["confidence"] > 0.5
