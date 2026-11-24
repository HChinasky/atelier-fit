def test_health_ok(client):
    resp = client.get("/health")
    assert resp.status_code == 200
    body = resp.json()
    assert body["status"] == "ok"
    assert body["model_loaded"] is True
    assert body["model"]


def test_health_reports_the_colour_engine_separately(client):
    """This service exists to detect poses. A deployment that never wanted the
    optional colour engine is healthy without it, so it gets its own field
    rather than dragging the overall status down."""
    body = client.get("/health").json()

    assert body["status"] == "ok"
    assert "color_engine" in body
    assert isinstance(body["color_engine"]["available"], bool)


def test_an_unavailable_colour_engine_says_why(client):
    body = client.get("/health").json()
    engine = body["color_engine"]

    if engine["available"]:
        assert engine["detail"] is None
    else:
        assert engine["detail"], "an operator has to be able to tell off from broken"


def test_pose_still_works_whatever_the_colour_engine_is_doing(client):
    """The guarantee: /pose, /preprocess-image and /health do not depend on the
    optional extra in any way."""
    from conftest import blank_image_bytes

    response = client.post(
        "/pose", files={"image": ("photo.jpg", blank_image_bytes(), "image/jpeg")}
    )

    assert response.status_code == 200
    assert response.json()["person_count"] == 0
