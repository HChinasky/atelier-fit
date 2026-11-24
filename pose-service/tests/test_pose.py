"""Behavioural tests for POST /pose against the real MediaPipe model."""
from __future__ import annotations

from conftest import (
    FULL_BODY,
    UPPER_BODY,
    WEBP_FULL,
    blank_image_bytes,
    read_bytes,
    side_by_side,
)

LEG_LANDMARKS = {"left_knee", "right_knee", "left_ankle", "right_ankle"}


def _post(client, data: bytes, filename="photo.jpg", content_type="image/jpeg"):
    return client.post(
        "/pose", files={"image": (filename, data, content_type)}
    )


def _landmarks_by_name(person):
    return {lm["name"]: lm for lm in person["landmarks"]}


def test_full_body_single_person_with_legs(client):
    resp = _post(client, read_bytes(FULL_BODY))
    assert resp.status_code == 200
    body = resp.json()

    assert body["person_count"] == 1
    assert body["model"]

    dims = body["image_dimensions"]
    assert dims["width"] > 0 and dims["height"] > 0

    person = body["main_person"]
    assert person is not None

    names = _landmarks_by_name(person)
    # 33-point BlazePose set, including legs/ankles/feet.
    assert len(person["landmarks"]) == 33
    assert LEG_LANDMARKS.issubset(names.keys())
    assert {"left_foot_index", "right_foot_index"}.issubset(names.keys())
    # Ankles should be confidently visible in a head-to-toe shot.
    assert names["left_ankle"]["confidence"] > 0.5
    assert names["right_ankle"]["confidence"] > 0.5

    # Bounding box is inside the image and non-degenerate.
    bbox = person["bounding_box"]
    assert bbox["width"] > 0 and bbox["height"] > 0
    assert 0 <= bbox["x"] <= dims["width"]
    assert 0 <= bbox["y"] <= dims["height"]
    assert bbox["x"] + bbox["width"] <= dims["width"] + 1
    assert bbox["y"] + bbox["height"] <= dims["height"] + 1

    # Landmark pixel coords agree with normalized coords * dimensions.
    nose = names["nose"]
    assert abs(nose["x"] - nose["x_norm"] * dims["width"]) < 1.0
    assert abs(nose["y"] - nose["y_norm"] * dims["height"]) < 1.0


def test_webp_full_body_is_decoded(client):
    resp = _post(client, read_bytes(WEBP_FULL), filename="p.webp", content_type="image/webp")
    assert resp.status_code == 200
    body = resp.json()
    assert body["person_count"] == 1
    assert body["main_person"] is not None


def test_no_person_returns_zero_not_error(client):
    resp = _post(client, blank_image_bytes())
    assert resp.status_code == 200
    body = resp.json()
    assert body["person_count"] == 0
    assert body["main_person"] is None
    # Dimensions are still reported for a blank image.
    assert body["image_dimensions"] == {"width": 640, "height": 480}


def test_multiple_people(client):
    resp = _post(client, side_by_side(FULL_BODY, UPPER_BODY))
    assert resp.status_code == 200
    body = resp.json()
    assert body["person_count"] > 1
    # main_person is still the most prominent one.
    assert body["main_person"] is not None
    assert len(body["main_person"]["landmarks"]) == 33


def test_corrupt_bytes_return_400(client):
    resp = _post(client, b"this is not an image at all")
    assert resp.status_code == 400
    assert "decode" in resp.json()["detail"].lower()


def test_empty_payload_returns_400(client):
    resp = _post(client, b"")
    assert resp.status_code == 400


def test_missing_file_returns_422(client):
    # No multipart field at all -> FastAPI validation error.
    resp = client.post("/pose")
    assert resp.status_code == 422


def test_oversized_upload_returns_413(client, monkeypatch):
    from app.config import get_settings

    settings = get_settings()
    monkeypatch.setattr(settings, "max_upload_bytes", 10)
    resp = _post(client, blank_image_bytes())
    assert resp.status_code == 413
