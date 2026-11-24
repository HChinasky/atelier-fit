"""In-memory image decoding. Nothing here ever touches disk."""
from __future__ import annotations

import io

import numpy as np
from PIL import Image, ImageOps, UnidentifiedImageError


class ImageDecodeError(ValueError):
    """Raised when bytes cannot be decoded into a usable RGB image."""


def decode_to_rgb(data: bytes) -> np.ndarray:
    """Decode raw image bytes into an RGB uint8 HxWx3 numpy array.

    Honours EXIF orientation so portrait phone photos are upright. Raises
    ImageDecodeError on empty or unreadable input. The decoded buffer lives
    only as long as the caller keeps the returned array.
    """
    if not data:
        raise ImageDecodeError("Empty image payload.")

    try:
        with Image.open(io.BytesIO(data)) as img:
            img = ImageOps.exif_transpose(img)  # respect camera rotation
            img = img.convert("RGB")
            arr = np.asarray(img, dtype=np.uint8)
    except (UnidentifiedImageError, OSError, ValueError) as exc:
        raise ImageDecodeError("Could not decode image data.") from exc

    if arr.ndim != 3 or arr.shape[2] != 3:
        raise ImageDecodeError("Decoded image is not a 3-channel RGB image.")
    if arr.shape[0] == 0 or arr.shape[1] == 0:
        raise ImageDecodeError("Decoded image has zero dimensions.")

    return arr
