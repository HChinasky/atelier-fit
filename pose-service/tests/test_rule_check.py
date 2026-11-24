"""POST /color-rule-check: today's thresholds, over numbers measured earlier.

These run wherever the ``color_engine`` package is importable, models or not.
That is the point of the endpoint and so it is the point of these: the tool
exists to review thresholds on a box that never had the MediaPipe artifacts and
never will, long after the photographs were deleted.

They skip only where the package itself is absent - a deployment that installed
pose detection alone - and there is one test for that state too.
"""
from __future__ import annotations

import pytest

pytest.importorskip("color_engine", reason="the colour engine package is not installed here")


def _sample(lightness: float, a: float, b: float) -> dict:
    return {
        "L": lightness,
        "a": a,
        "b": b,
        "C": (a**2 + b**2) ** 0.5,
        "h": 0.0,
        "pixels": 400,
        "L_iqr": 2.0,
    }


# The second live frame: fair skin pooled from two sub-zones because the third
# was blown, and two dark irises.
LIVE = {
    "skin": _sample(84.831, 10.771, 6.894),
    "skin_parts": {
        "left_cheek": _sample(82.784, 14.032, 7.393),
        "right_cheek": _sample(91.85, 2.187, 0.357),
        "forehead": _sample(86.877, 7.51, 6.395),
    },
    "hair": _sample(23.696, 5.906, 23.22),
    "iris": _sample(36.879, -3.142, -1.296),
    "iris_parts": {"left": _sample(32.676, -2.343, -1.312), "right": _sample(38.201, -3.994, -1.2)},
    "sclera": None,
    "sclera_parts": {"left": _sample(70.0, -0.5, 3.0), "right": _sample(70.0, -0.5, 3.0)},
}


def _post(client, samples: dict):
    return client.post("/color-rule-check", json={"samples": samples})


def test_it_answers_without_the_models(client):
    """The whole reason it is a separate endpoint. /color-analysis needs 20 MB
    of MediaPipe weights; this needs arithmetic, and must not be gated on them
    - a threshold review happens on a laptop, not on the box that serves
    photos."""
    response = _post(client, LIVE)

    assert response.status_code == 200


def test_it_says_which_check_decided_each_zone(client):
    body = _post(client, LIVE).json()

    cheek = body["zones"]["right_cheek"]
    assert cheek["usable"] is False
    assert cheek["reason"] == "overexposed"
    assert cheek["decided_by"] == "light_and_flat"

    assert body["zones"]["left_cheek"]["usable"] is True
    assert body["zones"]["left_cheek"]["decided_by"] is None


def test_it_reports_the_margin_on_every_check(client):
    body = _post(client, LIVE).json()

    checks = {c["name"]: c for c in body["zones"]["sclera_left"]["checks"]}
    under_skin = checks["not_darker_than_skin"]
    assert under_skin["passed"] is True
    # Skin at L* 84.8 against a sclera at L* 70 is 14.8 under, on a tolerance
    # of 18.2 - which is the number a threshold review is actually about.
    assert under_skin["value"] == pytest.approx(14.831, abs=1e-2)
    assert under_skin["margin"] > 0


def test_it_names_what_it_could_not_re_run(client):
    """A verdict with an unstated blind spot is the failure mode that makes a
    tool like this dangerous: the clipped-pixel count is gone with the photo,
    so a zone rejected only for that replays as clean."""
    body = _post(client, LIVE).json()

    assert "clipped_fraction" in body["zones"]["forehead"]["blind_spots"]
    assert "ab_spread" in body["zones"]["sclera_left"]["blind_spots"]


def test_it_reports_the_thresholds_it_used(client):
    """An answer read six months from now must not need a guess about which
    build produced it."""
    body = _post(client, LIVE).json()

    assert body["thresholds"]["sclera_socket_light_ratio"] > 0
    assert body["thresholds"]["sclera_over_iris_min_l"] > 0


def test_a_zone_with_no_numbers_is_not_judged(client):
    body = _post(client, {"skin": _sample(70.0, 12.0, 16.0)}).json()

    hair = body["zones"]["hair"]
    assert hair["replayed"] is False
    assert hair["usable"] is None


def test_malformed_samples_are_a_400_not_a_500(client):
    """A broken request, not a broken environment."""
    response = _post(client, {"skin": {"L": "not a number"}})

    assert response.status_code == 400
    assert "Malformed samples" in response.json()["detail"]


def test_it_stores_nothing_and_changes_nothing(client):
    """Read-only, and the cheapest possible proof of it: the same request twice
    gives byte-identical answers, and no other endpoint notices."""
    first = _post(client, LIVE).json()
    second = _post(client, LIVE).json()

    assert first == second
    assert client.get("/health").json()["status"] == "ok"
