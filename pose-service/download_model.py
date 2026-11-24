"""Download the model artifacts the service needs at runtime.

  1. The MediaPipe Pose Landmarker .task (~9 MB) into ./models.
  2. The rembg segmentation model (~176 MB for u2net) into the rembg cache, so
     production never fetches it on the first /preprocess-image request.

Both are build artifacts, not source — gitignored and fetched on demand. Run once
after creating your venv:

    python download_model.py
"""
from __future__ import annotations

import os
import sys
import urllib.request
from pathlib import Path

MODEL_URL = (
    "https://storage.googleapis.com/mediapipe-models/pose_landmarker/"
    "pose_landmarker_full/float16/latest/pose_landmarker_full.task"
)
DEST = Path(__file__).resolve().parent / "models" / "pose_landmarker_full.task"


def download_pose_model() -> None:
    DEST.parent.mkdir(parents=True, exist_ok=True)
    if DEST.exists() and DEST.stat().st_size > 0:
        print(f"Pose model already present: {DEST} ({DEST.stat().st_size} bytes)")
        return
    print(f"Downloading {MODEL_URL}")
    urllib.request.urlretrieve(MODEL_URL, DEST)
    print(f"Saved {DEST} ({DEST.stat().st_size} bytes)")


def download_segmentation_model() -> None:
    """Pre-warm the rembg model cache by creating a session (which downloads the
    model if absent). Skipped gracefully if rembg is not installed."""
    model = os.getenv("SEGMENT_MODEL", "u2net")
    try:
        from rembg import new_session
    except ImportError:
        print("rembg not installed; skipping segmentation model prefetch.")
        return
    print(f"Prefetching rembg segmentation model: {model}")
    new_session(model)  # downloads into U2NET_HOME / ~/.u2net on first use
    print(f"Segmentation model ready: {model}")


def main() -> int:
    download_pose_model()
    download_segmentation_model()
    return 0


if __name__ == "__main__":
    sys.exit(main())
