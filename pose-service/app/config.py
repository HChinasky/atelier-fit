"""Runtime configuration, read from environment / .env.

No secrets in code. Every value has a safe local-development default; the only
security-relevant one is POSE_API_TOKEN, which is empty by default (auth off)
because locally we rely on binding to 127.0.0.1.
"""
from __future__ import annotations

import os
from functools import lru_cache
from pathlib import Path

from dotenv import load_dotenv

# Load a .env sitting next to the project root if present. Never fails if absent.
load_dotenv()

# Resolve the model path relative to the service root so it works regardless of
# the current working directory uvicorn is launched from.
_SERVICE_ROOT = Path(__file__).resolve().parent.parent


def _env_int(name: str, default: int) -> int:
    raw = os.getenv(name)
    return int(raw) if raw not in (None, "") else default


def _env_float(name: str, default: float) -> float:
    raw = os.getenv(name)
    return float(raw) if raw not in (None, "") else default


def _env_bool(name: str, default: bool) -> bool:
    raw = os.getenv(name)
    if raw in (None, ""):
        return default
    return raw.strip().lower() in ("1", "true", "yes", "on")


def _parse_color(raw: str) -> tuple:
    """Parse '#rrggbb' or 'r,g,b' into an (r, g, b) tuple. Falls back to light
    grey on anything unparseable, so a typo never crashes startup."""
    fallback = (240, 240, 240)
    if not raw:
        return fallback
    raw = raw.strip()
    try:
        if raw.startswith("#"):
            h = raw[1:]
            if len(h) == 3:
                h = "".join(c * 2 for c in h)
            if len(h) != 6:
                return fallback
            return tuple(int(h[i : i + 2], 16) for i in (0, 2, 4))
        parts = [int(p) for p in raw.split(",")]
        if len(parts) == 3:
            return tuple(min(255, max(0, p)) for p in parts)
    except ValueError:
        return fallback
    return fallback


class Settings:
    """Immutable view over the process environment."""

    def __init__(self) -> None:
        self.host: str = os.getenv("POSE_SERVICE_HOST", "127.0.0.1")
        self.port: int = _env_int("POSE_SERVICE_PORT", 8000)

        # Empty token => authentication disabled (fine for localhost-only dev).
        self.api_token: str = os.getenv("POSE_API_TOKEN", "")

        model_path = os.getenv("POSE_MODEL_PATH", "models/pose_landmarker_full.task")
        p = Path(model_path)
        self.model_path: Path = p if p.is_absolute() else _SERVICE_ROOT / p

        # How many people to look for. >1 is what lets us report person_count > 1.
        self.max_persons: int = _env_int("POSE_MAX_PERSONS", 6)
        self.min_pose_detection_confidence: float = _env_float(
            "POSE_MIN_DETECTION_CONFIDENCE", 0.5
        )
        self.min_pose_presence_confidence: float = _env_float(
            "POSE_MIN_PRESENCE_CONFIDENCE", 0.5
        )

        # Landmarks below this confidence are excluded from the bounding box so
        # extrapolated/off-frame points cannot inflate it.
        self.bbox_min_confidence: float = _env_float(
            "POSE_BBOX_MIN_CONFIDENCE", 0.5
        )

        # Reject oversized uploads before decoding (defensive; default 15 MiB).
        self.max_upload_bytes: int = _env_int(
            "POSE_MAX_UPLOAD_BYTES", 15 * 1024 * 1024
        )

        # --- Seasonal colour analysis (/color-analysis) -----------------------
        # An OPTIONAL extra. This service exists to detect poses, and a
        # deployment that only wants that should not have to install a second
        # set of models to get it. Off by default; /pose, /preprocess-image
        # and /health never touch any of this.
        self.color_enabled: bool = _env_bool("COLOR_ENGINE_ENABLED", False)

        # Where the color-engine MediaPipe artifacts live. Unset falls back to
        # the library's own default (services/color-engine/models), which only
        # exists when the package is installed editable from a source checkout —
        # so a non-editable production install has to set this. Everything else
        # the engine needs it reads from the environment itself, under the
        # COLOR_ENGINE_ prefix (load_dotenv above has already put this service's
        # .env there); COLOR_ENGINE_WHITE_BALANCE_ENABLED is the one to know
        # about, and it defaults to off in the library.
        raw_color_models = os.getenv("COLOR_ENGINE_MODELS_DIR", "")
        self.color_models_dir = Path(raw_color_models) if raw_color_models else None

        # --- Image enhancement (/preprocess-image): lighting + segmentation ---
        # Whether the rembg model is kept resident between calls. Default False
        # (release after each call) to keep RAM headroom on the GPU-less box;
        # MediaPipe is already pinned in this process. Flip to True only if a
        # memory check shows room and the reload latency matters.
        self.segment_keep_loaded: bool = _env_bool("SEGMENT_KEEP_LOADED", False)

        # rembg model: u2net (quality, default), u2netp (light), isnet-general-use.
        self.segment_model: str = os.getenv("SEGMENT_MODEL", "u2net")

        # Solid neutral studio background the cut-out person is composited onto.
        # Light grey by default (never transparent — the VTON engine gets opaque
        # pixels). Accepts #rrggbb or "r,g,b".
        self.bg_color: tuple = _parse_color(os.getenv("PREPROCESS_BG_COLOR", "#f0f0f0"))

        # Lighting normalization tunables (classical CV, see app/enhance.py).
        self.clahe_clip: float = _env_float("PREPROCESS_CLAHE_CLIP", 2.0)
        self.clahe_tile: int = _env_int("PREPROCESS_CLAHE_TILE", 8)
        self.white_balance: bool = _env_bool("PREPROCESS_WHITE_BALANCE", True)
        self.gamma_correct: bool = _env_bool("PREPROCESS_GAMMA", True)
        self.gamma_target: float = _env_float("PREPROCESS_GAMMA_TARGET", 0.5)

        # Output quality for re-encoded lossy formats (jpeg/webp), 1–100.
        self.output_quality: int = _env_int("PREPROCESS_OUTPUT_QUALITY", 90)

    @property
    def auth_enabled(self) -> bool:
        return bool(self.api_token)


@lru_cache(maxsize=1)
def get_settings() -> Settings:
    return Settings()
