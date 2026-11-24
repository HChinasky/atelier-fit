"""Image enhancement for the input (person) photo: lighting normalization and
person segmentation onto a neutral studio background.

Both run on CPU and operate on an RGB uint8 HxWx3 numpy array, returning the same
shape (dimensions are NEVER changed — the backend's crop step reuses pose
landmark pixel coordinates computed on the pre-enhancement image, so any resize
here would silently break the crop geometry).

Memory: this box has no GPU and limited RAM (MediaPipe is already resident in this
process). The heavy libraries are therefore imported lazily — OpenCV only when
lighting runs, rembg/onnxruntime only when segmentation runs — and the rembg
session is, by default, released after every call (``keep_loaded=False``) so the
~hundreds of MB it needs do not stay pinned between generations. Detection volume
is low (lead-gated funnel), so the reload cost is an acceptable trade for headroom.

Privacy: operates on pixels in memory only; nothing is written to disk or logged.
"""
from __future__ import annotations

import gc
import io
import logging
import math
import threading
from typing import Tuple

import numpy as np
from PIL import Image

logger = logging.getLogger("pose-service")

# rembg is serialized behind a lock: onnxruntime sessions are not guaranteed
# thread-safe, and serializing also caps peak memory to a single inference at a
# time (FastAPI runs sync endpoints in a thread pool).
_seg_lock = threading.Lock()
_seg_state: dict = {"session": None, "model": None}


# --------------------------------------------------------------------------- #
# Lighting normalization (classical CV — no ML, fast and predictable on CPU)
# --------------------------------------------------------------------------- #
def normalize_lighting(
    rgb: np.ndarray,
    *,
    clahe_clip: float = 2.0,
    clahe_tile: int = 8,
    white_balance: bool = True,
    gamma_correct: bool = True,
    gamma_target: float = 0.5,
) -> np.ndarray:
    """Even out exposure / contrast / colour cast of a photo.

    Pipeline (conservative so a good photo is barely touched):
      1. CLAHE on the L channel of LAB — local contrast, fixing under/over
         exposure without blowing out colour.
      2. Gray-world white balance — neutralises colour casts (clamped so it can
         never invent an extreme tint).
      3. Auto gamma toward a mid target luminance — lifts dark photos / tames
         bright ones (exponent clamped so the correction stays gentle).
    """
    import cv2

    lab = cv2.cvtColor(rgb, cv2.COLOR_RGB2LAB)
    l, a, b = cv2.split(lab)
    clahe = cv2.createCLAHE(
        clipLimit=float(clahe_clip), tileGridSize=(int(clahe_tile), int(clahe_tile))
    )
    l = clahe.apply(l)
    out = cv2.cvtColor(cv2.merge((l, a, b)), cv2.COLOR_LAB2RGB)

    if white_balance:
        out = _gray_world(out)
    if gamma_correct:
        out = _auto_gamma(out, gamma_target)

    return out


def _gray_world(rgb: np.ndarray) -> np.ndarray:
    """Gray-world assumption: scale channels so their means converge to a common
    gray. Per-channel scale is clamped to [0.5, 2.0] so a strongly coloured
    (legitimately red/blue) photo is not wrecked."""
    img = rgb.astype(np.float32)
    means = img.reshape(-1, 3).mean(axis=0)
    gray = float(means.mean())
    scale = np.clip(gray / np.clip(means, 1e-6, None), 0.5, 2.0)
    img *= scale.reshape(1, 1, 3)
    return np.clip(img, 0, 255).astype(np.uint8)


def _auto_gamma(rgb: np.ndarray, target: float) -> np.ndarray:
    """Map the image's mean luminance toward ``target`` (0..1) via a gamma curve.

    exponent solves target = mean ** exp; clamped to [0.4, 2.5] so the curve is
    always gentle (a near-correct photo gets exp ~1 = a no-op)."""
    mean = float(rgb.astype(np.float32).mean()) / 255.0
    mean = min(max(mean, 1e-3), 0.999)
    target = min(max(float(target), 0.05), 0.95)
    exp = math.log(target) / math.log(mean)
    exp = min(max(exp, 0.4), 2.5)
    lut = (np.clip((np.arange(256) / 255.0) ** exp, 0.0, 1.0) * 255.0).astype(np.uint8)
    return lut[rgb]


# --------------------------------------------------------------------------- #
# Person segmentation (rembg / U^2-Net family) onto a neutral background
# --------------------------------------------------------------------------- #
def segment_on_background(
    rgb: np.ndarray,
    *,
    model_name: str = "u2net",
    bg_color: Tuple[int, int, int] = (240, 240, 240),
    keep_loaded: bool = False,
) -> np.ndarray:
    """Cut the person out and composite onto a SOLID neutral background.

    Returns RGB (never transparent) so the downstream VTON engine receives a
    clean, opaque image — transparency is deliberately avoided. The alpha matte
    is feathered by rembg, so edges blend into the background rather than leaving
    a hard cut-out line.
    """
    from rembg import remove

    with _seg_lock:
        session = _get_session(model_name)
        try:
            cutout = remove(rgb, session=session)  # RGBA HxWx4 for ndarray input
        finally:
            if not keep_loaded:
                _release_session()

    return composite_on_background(np.asarray(cutout), bg_color)


def composite_on_background(
    cutout_rgba: np.ndarray, bg_color: Tuple[int, int, int]
) -> np.ndarray:
    """Alpha-composite an RGBA cut-out onto a solid background, returning opaque
    RGB. Pure array math (no model), so the compositing contract is testable on
    its own."""
    if cutout_rgba.ndim != 3 or cutout_rgba.shape[2] != 4:
        # No alpha channel — nothing reliable to composite.
        raise RuntimeError("segmentation returned no alpha channel")

    alpha = cutout_rgba[:, :, 3:4].astype(np.float32) / 255.0
    fg = cutout_rgba[:, :, :3].astype(np.float32)
    bg = np.array(bg_color, dtype=np.float32).reshape(1, 1, 3)
    out = fg * alpha + bg * (1.0 - alpha)
    return np.clip(out, 0, 255).astype(np.uint8)


def _get_session(model_name: str):
    cached = _seg_state["session"]
    if cached is not None and _seg_state["model"] == model_name:
        return cached
    if cached is not None:
        _release_session()
    from rembg import new_session

    session = new_session(model_name)
    _seg_state["session"] = session
    _seg_state["model"] = model_name
    return session


def _release_session() -> None:
    """Drop the rembg session and reclaim its memory."""
    _seg_state["session"] = None
    _seg_state["model"] = None
    gc.collect()


# --------------------------------------------------------------------------- #
# Encoding
# --------------------------------------------------------------------------- #
def encode(rgb: np.ndarray, fmt: str, quality: int) -> Tuple[bytes, str]:
    """Encode an RGB array to bytes in the requested format. Returns (bytes,
    media_type). Falls back to JPEG for an unknown format."""
    fmt = (fmt or "jpeg").lower()
    image = Image.fromarray(rgb, mode="RGB")
    buf = io.BytesIO()
    if fmt == "png":
        image.save(buf, format="PNG")
        return buf.getvalue(), "image/png"
    if fmt == "webp":
        image.save(buf, format="WEBP", quality=int(quality))
        return buf.getvalue(), "image/webp"
    image.save(buf, format="JPEG", quality=int(quality))
    return buf.getvalue(), "image/jpeg"
