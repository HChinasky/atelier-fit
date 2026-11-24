"""POST /garment-colour against the real colour engine.

The seam, not the colour science (color-engine's test_garment.py has that):
what a broken request does, what an unmeasurable photo does, and that a real
catalog photo comes back with its colour.
"""
from __future__ import annotations

import io

import pytest
from conftest import CATALOG, read_bytes
from PIL import Image

pytestmark = pytest.mark.requires_color_models


@pytest.fixture(autouse=True)
def _needs_engine(color_engine_ready: bool):
    if not color_engine_ready:
        pytest.skip(
            "colour analysis is unavailable here. Set COLOR_ENGINE_ENABLED=true and "
            "COLOR_ENGINE_MODELS_DIR, or run services/color-engine/scripts/fetch_models.py"
        )


def _post(client, data: bytes):
    return client.post("/garment-colour", files={"image": ("product.jpg", data, "image/jpeg")})


def _png(size=(300, 400), backdrop=(250, 250, 250), garment=None) -> bytes:
    image = Image.new("RGB", size, backdrop)
    if garment is not None:
        image.paste(garment, (60, 80, size[0] - 60, size[1] - 80))
    buffer = io.BytesIO()
    image.save(buffer, format="PNG")
    return buffer.getvalue()


def test_a_request_without_an_image_is_rejected(client):
    assert client.post("/garment-colour", data={}).status_code == 422


def test_an_empty_image_is_a_bad_request(client):
    response = _post(client, b"")

    assert response.status_code == 400
    assert "empty" in response.json()["detail"].lower()


def test_a_flat_red_garment_comes_back_red(client):
    response = _post(client, _png(garment=(190, 20, 30)))

    assert response.status_code == 200
    result = response.json()["result"]
    assert result["status"] == "ok"
    assert result["method"] == "background"
    assert result["colours"][0]["hex"] == "#BE141E"
    assert result["colours"][0]["share"] == 1.0
    assert result["algo_version"].startswith("garment-")


def test_a_garment_the_colour_of_its_backdrop_is_a_200_refusal(client):
    response = _post(client, _png())

    assert response.status_code == 200
    assert response.json()["result"]["status"] == "rejected"
    assert response.json()["result"]["reject_reason"] == "no_garment"


def test_a_real_catalog_photo_is_measured(client):
    photo = CATALOG / "onebyone-dress-maxi-red-deep-v.jpg"
    if not photo.exists():
        pytest.skip(f"catalog photo not found: {photo}")

    result = _post(client, read_bytes(photo)).json()["result"]

    assert result["status"] == "ok"
    lab = result["colours"][0]
    assert lab["a"] > 30 and lab["L"] < 50  # a deep red
