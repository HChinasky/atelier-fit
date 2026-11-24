"""Behavioural tests for POST /preprocess-image (lighting + segmentation).

Lighting runs the real OpenCV path (cheap, deterministic). Segmentation is tested
two ways without pulling the ~176 MB rembg model into CI:
  * the pure compositing helper is tested directly on a synthetic RGBA cut-out;
  * the endpoint's segment wiring (headers, graceful failure) is tested by
    monkeypatching the segmentation call.
A real end-to-end rembg run is opt-in via REMBG_REAL=1 for local verification.
"""
from __future__ import annotations

import io
import os

import numpy as np
import pytest
from PIL import Image

from app import enhance


def _img_bytes(arr: np.ndarray, fmt: str = "JPEG") -> bytes:
    buf = io.BytesIO()
    Image.fromarray(arr, mode="RGB").save(buf, format=fmt)
    return buf.getvalue()


def _dark(width=120, height=160) -> np.ndarray:
    return np.full((height, width, 3), 30, dtype=np.uint8)


def _bright(width=120, height=160) -> np.ndarray:
    return np.full((height, width, 3), 235, dtype=np.uint8)


def _post(client, data, *, lighting=False, segment=False, fmt="jpeg"):
    return client.post(
        "/preprocess-image",
        files={"image": ("photo.jpg", data, "image/jpeg")},
        data={"lighting": str(lighting).lower(), "segment": str(segment).lower(), "format": fmt},
    )


def _decode(content: bytes) -> np.ndarray:
    return np.asarray(Image.open(io.BytesIO(content)).convert("RGB"))


# --------------------------------------------------------------------------- #
# Lighting normalization
# --------------------------------------------------------------------------- #
def test_lighting_brightens_a_dark_photo(client):
    resp = _post(client, _img_bytes(_dark()), lighting=True)
    assert resp.status_code == 200
    assert resp.headers["x-applied-lighting"] == "true"
    assert resp.headers["x-applied-segment"] == "false"

    out = _decode(resp.content)
    assert out.mean() > _dark().mean()  # lifted toward mid


def test_lighting_tames_a_bright_photo(client):
    resp = _post(client, _img_bytes(_bright()), lighting=True)
    assert resp.status_code == 200
    out = _decode(resp.content)
    assert out.mean() < _bright().mean()  # pulled down toward mid


def test_lighting_preserves_dimensions(client):
    src = _dark(123, 211)
    resp = _post(client, _img_bytes(src), lighting=True)
    out = _decode(resp.content)
    assert out.shape[:2] == src.shape[:2]  # H, W unchanged (crop depends on this)


def test_no_flags_is_a_passthrough_reencode(client):
    resp = _post(client, _img_bytes(_dark()))
    assert resp.status_code == 200
    assert resp.headers["x-applied-lighting"] == "false"
    assert resp.headers["x-applied-segment"] == "false"
    out = _decode(resp.content)
    assert out.shape[:2] == (160, 120)


def test_output_format_png(client):
    resp = _post(client, _img_bytes(_dark()), lighting=True, fmt="png")
    assert resp.status_code == 200
    assert resp.headers["content-type"] == "image/png"


def test_corrupt_bytes_return_400(client):
    resp = _post(client, b"not an image", lighting=True)
    assert resp.status_code == 400


def test_oversized_upload_returns_413(client, monkeypatch):
    from app.config import get_settings

    settings = get_settings()
    monkeypatch.setattr(settings, "max_upload_bytes", 10)
    resp = _post(client, _img_bytes(_dark()), lighting=True)
    assert resp.status_code == 413


# --------------------------------------------------------------------------- #
# Segmentation — compositing helper (no model needed)
# --------------------------------------------------------------------------- #
def test_composite_puts_person_on_neutral_bg_and_is_opaque():
    h, w = 10, 8
    rgba = np.zeros((h, w, 4), dtype=np.uint8)
    # A fully-opaque red person block in the centre; everything else transparent.
    rgba[2:8, 2:6, :3] = (200, 0, 0)
    rgba[2:8, 2:6, 3] = 255

    out = enhance.composite_on_background(rgba, (240, 240, 240))

    assert out.shape == (h, w, 3)  # no alpha channel: opaque, not transparent
    # Background pixels are the neutral grey, not black/transparent.
    assert tuple(out[0, 0]) == (240, 240, 240)
    # The person is preserved where alpha was opaque.
    assert tuple(out[4, 3]) == (200, 0, 0)


def test_composite_rejects_image_without_alpha():
    with pytest.raises(RuntimeError):
        enhance.composite_on_background(np.zeros((4, 4, 3), dtype=np.uint8), (240, 240, 240))


# --------------------------------------------------------------------------- #
# Segmentation — endpoint wiring (monkeypatched model)
# --------------------------------------------------------------------------- #
def test_segment_flag_applies_segmentation(client, monkeypatch):
    def fake_segment(rgb, **kwargs):
        # Return a same-size image on the configured neutral background.
        bg = np.array(kwargs["bg_color"], dtype=np.uint8)
        return np.broadcast_to(bg, rgb.shape).copy()

    monkeypatch.setattr(enhance, "segment_on_background", fake_segment)

    resp = _post(client, _img_bytes(_dark(64, 96)), segment=True)
    assert resp.status_code == 200
    assert resp.headers["x-applied-segment"] == "true"
    out = _decode(resp.content)
    assert out.shape[:2] == (96, 64)  # dimensions preserved


def test_segmentation_failure_is_graceful(client, monkeypatch):
    # rembg blowing up must NOT fail the request: the (lit) image still returns,
    # the header reports segment=false, and the backend's fail-open never trips.
    def boom(rgb, **kwargs):
        raise RuntimeError("model exploded")

    monkeypatch.setattr(enhance, "segment_on_background", boom)

    resp = _post(client, _img_bytes(_dark()), lighting=True, segment=True)
    assert resp.status_code == 200
    assert resp.headers["x-applied-lighting"] == "true"
    assert resp.headers["x-applied-segment"] == "false"


@pytest.mark.skipif(os.getenv("REMBG_REAL") != "1", reason="set REMBG_REAL=1 to run the real rembg model")
def test_real_segmentation_end_to_end(client):
    from tests.conftest import FULL_BODY, read_bytes  # type: ignore

    resp = _post(client, read_bytes(FULL_BODY), segment=True, fmt="jpeg")
    assert resp.status_code == 200
    assert resp.headers["x-applied-segment"] == "true"
    out = _decode(resp.content)
    assert out.ndim == 3 and out.shape[2] == 3  # opaque
