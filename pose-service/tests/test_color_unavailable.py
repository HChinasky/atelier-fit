"""What /color-analysis does when the optional engine is not there.

The mirror of test_color_analysis.py: those tests need the engine, these need it
to be ABSENT. Between them one of the two files runs whatever the environment,
so neither state ships untested.
"""
from __future__ import annotations

import pytest
from conftest import blank_image_bytes


@pytest.fixture(autouse=True)
def _needs_no_engine(color_engine_ready: bool):
    if color_engine_ready:
        pytest.skip("the colour engine is available here; see test_color_analysis.py")


def test_the_endpoint_answers_503_rather_than_404(client):
    """503, not 404 and not 501: the endpoint exists in this build, it just
    cannot serve a request in this deployment. A 404 would read as a version
    mismatch and send somebody looking for the wrong bug."""
    response = client.post(
        "/color-analysis",
        files={"image": ("photo.jpg", blank_image_bytes(), "image/jpeg")},
    )

    assert response.status_code == 503


def test_the_503_says_why(client):
    """An operator reading one log line has to be able to tell a switch that is
    off from a model set that is missing."""
    response = client.post(
        "/color-analysis",
        files={"image": ("photo.jpg", blank_image_bytes(), "image/jpeg")},
    )

    detail = response.json()["detail"]
    assert isinstance(detail, str) and detail.strip()
    assert "COLOR_ENGINE_ENABLED" in detail or "models" in detail or "installed" in detail


def test_health_agrees_with_the_endpoint(client):
    body = client.get("/health").json()

    assert body["color_engine"]["available"] is False
    assert body["color_engine"]["detail"]
    # ...and the service itself is still fine.
    assert body["status"] == "ok"
    assert body["model_loaded"] is True


def test_the_other_endpoints_are_untouched(client):
    """The whole point of making it optional."""
    pose = client.post(
        "/pose", files={"image": ("photo.jpg", blank_image_bytes(), "image/jpeg")}
    )
    preprocess = client.post(
        "/preprocess-image",
        files={"image": ("photo.jpg", blank_image_bytes(), "image/jpeg")},
    )

    assert pose.status_code == 200
    assert preprocess.status_code == 200


def test_the_garment_endpoint_is_behind_the_same_switch(client):
    """It uses the engine's segmenter, so it needs what the switch loads."""
    response = client.post(
        "/garment-colour",
        files={"image": ("product.jpg", blank_image_bytes(), "image/jpeg")},
    )

    assert response.status_code == 503
    assert response.json()["detail"].strip()
