"""Shared fixtures.

The detector loads the real MediaPipe model once per test session (it is fast on
CPU and small), so tests exercise the genuine detection path end to end. No
external network or service calls are made: the model is a local file and the
images are local fixtures or generated in-memory.

The colour engine is optional and the service starts without it, so the client
fixture never skips on its account. Tests that need it ask ``color_engine_ready``
and skip individually.
"""
from __future__ import annotations

import io
from pathlib import Path

import numpy as np
import pytest
from fastapi.testclient import TestClient
from PIL import Image

# Real photos that ship with the main app's dev catalog, reused as fixtures.
CATALOG = Path(__file__).resolve().parents[2] / "public" / "dev-catalog"
FULL_BODY = CATALOG / "woman-model.jpg"          # single person, head-to-toe
UPPER_BODY = CATALOG / "selfie.jpg"              # single person, upper body
WEBP_FULL = CATALOG / "full-body-young-smiling-fun-260nw-2300913357.webp"


@pytest.fixture(scope="session")
def client():
    """The app, started for real.

    It no longer matters whether the colour engine is installed: it is an
    optional extra and the service starts without it, so this fixture never
    skips. Only the /color-analysis tests do — see ``color_engine_ready``.
    """
    from app.main import app

    with TestClient(app) as c:  # triggers lifespan -> loads the pose model
        yield c


@pytest.fixture(scope="session")
def color_engine_ready(client) -> bool:
    """Can this environment actually serve /color-analysis?

    Asked of the running service rather than of the filesystem, so it covers
    every reason the answer might be no — switched off, package absent, models
    absent — with one check and no duplicated logic.
    """
    body = client.get("/health").json()

    return bool(body.get("color_engine", {}).get("available"))


def read_bytes(path: Path) -> bytes:
    return path.read_bytes()


def blank_image_bytes(width: int = 640, height: int = 480, color=(200, 200, 200)) -> bytes:
    """A plain solid-colour image — contains no person."""
    img = Image.new("RGB", (width, height), color)
    buf = io.BytesIO()
    img.save(buf, format="JPEG")
    return buf.getvalue()


def side_by_side(path_a: Path, path_b: Path) -> bytes:
    """Compose two single-person photos into one image (two people)."""
    a = Image.open(io.BytesIO(path_a.read_bytes())).convert("RGB")
    b = Image.open(io.BytesIO(path_b.read_bytes())).convert("RGB")
    h = max(a.height, b.height)
    # Scale each to the same height, keep aspect ratio.
    a = a.resize((int(a.width * h / a.height), h))
    b = b.resize((int(b.width * h / b.height), h))
    canvas = Image.new("RGB", (a.width + b.width, h), (255, 255, 255))
    canvas.paste(a, (0, 0))
    canvas.paste(b, (a.width, 0))
    buf = io.BytesIO()
    canvas.save(buf, format="JPEG")
    return buf.getvalue()
