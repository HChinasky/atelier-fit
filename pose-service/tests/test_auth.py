"""Auth gate: when a token is configured it is enforced; otherwise it is a no-op.

A fresh app instance is built with auth enabled so the session-wide `client`
fixture (auth disabled) is unaffected.
"""
from __future__ import annotations

import importlib

from fastapi.testclient import TestClient

from conftest import blank_image_bytes


def _client_with_token(monkeypatch, token="secret-token"):
    monkeypatch.setenv("POSE_API_TOKEN", token)
    import app.config as config

    config.get_settings.cache_clear()  # drop cached (token-less) settings
    import app.main as main

    importlib.reload(main)
    return TestClient(main.app), config


def test_request_without_token_is_rejected(monkeypatch):
    client, config = _client_with_token(monkeypatch)
    try:
        with client:
            resp = client.post(
                "/pose", files={"image": ("b.jpg", blank_image_bytes(), "image/jpeg")}
            )
        assert resp.status_code == 401
    finally:
        config.get_settings.cache_clear()


def test_request_with_correct_token_is_accepted(monkeypatch):
    client, config = _client_with_token(monkeypatch)
    try:
        with client:
            resp = client.post(
                "/pose",
                files={"image": ("b.jpg", blank_image_bytes(), "image/jpeg")},
                headers={"X-API-Token": "secret-token"},
            )
        assert resp.status_code == 200
        assert resp.json()["person_count"] == 0
    finally:
        config.get_settings.cache_clear()


def test_health_does_not_require_token(monkeypatch):
    client, config = _client_with_token(monkeypatch)
    try:
        with client:
            resp = client.get("/health")
        assert resp.status_code == 200
    finally:
        config.get_settings.cache_clear()
