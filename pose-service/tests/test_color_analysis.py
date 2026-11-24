"""POST /color-analysis against the real colour engine.

The engine is a library; this endpoint is the only HTTP surface it has. The
tests here pin the seam rather than the colour science — that is covered by
color-engine's own suite — so they check the contract: what a broken request
does, what a photo the engine refuses does, and what debug mode returns.
"""
from __future__ import annotations

import base64
import io
import json

import pytest
from conftest import blank_image_bytes, read_bytes
from PIL import Image

pytestmark = pytest.mark.requires_color_models


@pytest.fixture(autouse=True)
def _needs_engine(color_engine_ready: bool):
    """Skip just these tests when the optional engine is not there.

    Not the whole suite: the service starts and serves /pose perfectly well
    without it, and a run that skipped everything would hide a pose regression
    behind a missing colour model.
    """
    if not color_engine_ready:
        pytest.skip(
            "colour analysis is unavailable here. Set COLOR_ENGINE_ENABLED=true and "
            "COLOR_ENGINE_MODELS_DIR, or run services/color-engine/scripts/fetch_models.py"
        )

PNG_MAGIC = b"\x89PNG\r\n\x1a\n"


def _post(client, data: bytes = None, filename="photo.jpg", **fields):
    payload = blank_image_bytes() if data is None else data
    return client.post(
        "/color-analysis",
        files={"image": (filename, payload, "image/jpeg")},
        data={k: v for k, v in fields.items() if v is not None},
    )


# --- a broken request is a 4xx --------------------------------------------


def test_a_request_without_an_image_is_rejected(client):
    resp = client.post("/color-analysis", data={})

    assert resp.status_code == 422


def test_an_empty_image_is_a_bad_request(client):
    resp = _post(client, data=b"")

    assert resp.status_code == 400
    assert "empty" in resp.json()["detail"].lower()


def test_a_malformed_quiz_is_a_bad_request(client):
    """A broken request, not a bad photograph: the caller sent us nonsense."""
    resp = _post(client, quiz="{not json")

    assert resp.status_code == 400
    assert "quiz" in resp.json()["detail"].lower()


def test_an_unknown_quiz_answer_is_a_bad_request(client):
    resp = _post(client, quiz=json.dumps({"metal": "bronze"}))

    assert resp.status_code == 400


def test_an_oversized_image_is_refused_by_the_engines_own_limit(client):
    """The caller gets the same answer whether the engine is reached over HTTP
    or imported directly, so the limit is the engine's, not this service's."""
    from color_engine import EngineConfig

    too_big = b"\xff" * (EngineConfig().max_input_bytes + 1)

    resp = _post(client, data=too_big)

    assert resp.status_code == 413


# --- a photo the engine refuses is a 200 ----------------------------------


def test_a_photo_with_no_face_is_a_normal_two_hundred(client):
    """status='rejected' is a fact about the photograph, not an HTTP error."""
    resp = _post(client)

    assert resp.status_code == 200
    result = resp.json()["result"]
    assert result["status"] == "rejected"
    assert result["reject_reason"] == "no_face"
    assert result["algo_version"]


def test_the_result_is_the_engines_own_contract(client):
    """Passed through untouched — this service does not reshape it."""
    resp = _post(client)

    result = resp.json()["result"]
    for key in ("status", "reject_reason", "samples", "white_balance", "axes", "algo_version"):
        assert key in result, key
    assert set(result["white_balance"]) == {
        # `enabled` and `reason` say whether the step ran at all and why it did
        # nothing — `method: none` alone covers a deployment that never wanted
        # correction and a frame the engine could not correct, which are not the
        # same answer.
        "enabled",
        "reason",
        "method",
        "single_eye",
        "correction_strength",
        "estimated_cct",
        "target_calibrated",
    }


def test_a_valid_quiz_is_accepted(client):
    resp = _post(client, quiz=json.dumps({"metal": "gold", "natural_hair": True}))

    assert resp.status_code == 200


# --- debug mode ------------------------------------------------------------


def test_debug_is_off_by_default(client):
    resp = _post(client)

    assert resp.json()["debug_images"] is None


def test_debug_returns_base64_png_views(client):
    """A rejected blank image has no zones, so it renders no zone views - but it
    still renders the decoded photograph, which is the one thing somebody
    looking at a refusal needs to see."""
    import base64

    resp = _post(client, debug="true")

    assert resp.status_code == 200
    views = resp.json()["debug_images"]
    assert set(views) == {"source_preview"}
    magic = bytes([137, 80, 78, 71, 13, 10, 26, 10])
    assert base64.b64decode(views["source_preview"]).startswith(magic)


def test_debug_on_a_real_face_renders_the_views(client):
    """The one photo in the repository with a face big enough to measure may
    still be refused by the size floors; either way debug must not error, and
    whatever it renders must be real PNG."""
    from conftest import UPPER_BODY

    resp = _post(client, data=read_bytes(UPPER_BODY), debug="true")

    assert resp.status_code == 200
    images = resp.json()["debug_images"]
    assert isinstance(images, dict)
    for name, payload in images.items():
        decoded = base64.b64decode(payload)
        assert decoded.startswith(PNG_MAGIC), name
        with Image.open(io.BytesIO(decoded)) as image:
            assert image.format == "PNG"


# --- the bytes arrive untouched -------------------------------------------


def test_lighting_normalization_is_not_applied_here(client):
    """/preprocess-image rewrites exactly the colours this endpoint measures.
    None of it may reach this path, in any form."""
    import inspect

    from app import main

    source = inspect.getsource(main.color_analysis)

    assert "enhance" not in source
    assert "normalize_lighting" not in source
    assert "decode_to_rgb" not in source, "the engine does its own colour management"
