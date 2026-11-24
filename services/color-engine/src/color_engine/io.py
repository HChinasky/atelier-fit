"""Decoding input bytes into an sRGB array we can measure.

Colour accuracy starts here. A photo from an iPhone carries a Display P3 ICC
profile; reading its pixels as if they were sRGB shifts every hue outward and
would poison the whole seasonal call. So: honour the embedded profile, convert
to sRGB, and record what we found.

Nothing in this module writes to disk or logs pixel data.
"""

from __future__ import annotations

import io as _io
from contextlib import suppress
from dataclasses import dataclass
from pathlib import PurePath
from typing import Any

import numpy as np
from PIL import Image, ImageCms, ImageOps, UnidentifiedImageError

from .arrays import ImageArray
from .config import EngineConfig
from .errors import FileTooLarge, ImageTooSmall, UnsupportedFormat

# HEIC/HEIF is what iOS actually uploads. Registering the plugin makes
# Image.open transparently handle it.
try:  # pragma: no cover - import side effect
    import pillow_heif

    pillow_heif.register_heif_opener()
    HEIF_AVAILABLE = True
except ImportError:  # pragma: no cover - dependency is declared, this is belt and braces
    HEIF_AVAILABLE = False

# Everything else (TIFF, BMP, GIF, PDF...) is rejected rather than silently
# accepted: those are not phone photos and we have no colour story for them.
#
# MPO is here because Pillow reports it for any JPEG carrying more than one
# image - which includes ordinary photos from current phones, where the extra
# frame is an HDR gain map. Dropping MPO would reject a plain iPhone shot. We
# always measure frame 0, the primary image; see _first_frame.
#
# One table, two consumers. ``load_image`` checks the format Pillow *decoded*;
# the CLI has to decide which files in a directory are worth opening at all, and
# can only go on the extension. Deriving both from the same mapping is what
# stops the two drifting apart - an MPO on disk is named ``.jpg`` far more often
# than ``.mpo``, and a list maintained by hand loses that.
FORMAT_SUFFIXES: dict[str, tuple[str, ...]] = {
    "JPEG": (".jpg", ".jpeg"),
    "PNG": (".png",),
    "WEBP": (".webp",),
    "HEIF": (".heic", ".heif"),
    "HEIC": (".heic", ".heif"),
    "MPO": (".mpo", ".jpg", ".jpeg"),
}

SUPPORTED_FORMATS = frozenset(FORMAT_SUFFIXES)

SUPPORTED_SUFFIXES = frozenset(
    suffix for suffixes in FORMAT_SUFFIXES.values() for suffix in suffixes
)
"""Lower-case extensions any supported format is stored under."""


def is_supported_suffix(name: str | PurePath) -> bool:
    """Does this filename carry an extension we can decode?

    Case-insensitive on purpose: cameras write ``IMG_0001.JPG`` and iOS writes
    ``.HEIC``, and a set membership test on the raw suffix silently skips both.
    """
    return PurePath(name).suffix.lower() in SUPPORTED_SUFFIXES


SRGB_ASSUMED = "sRGB (assumed)"


@dataclass(frozen=True)
class LoadedImage:
    """An image ready for measurement."""

    rgb: ImageArray
    """uint8 HxWx3, sRGB primaries and transfer function."""

    width: int
    height: int
    source_color_space: str
    """Embedded ICC profile description, or ``sRGB (assumed)`` when untagged."""

    source_format: str
    original_width: int
    original_height: int
    downscaled: bool

    @property
    def source_scale(self) -> float:
        """Original pixels per analysed pixel, never below 1.

        ``_downscale`` only ever shrinks and nothing in this module enlarges, so
        this is >= 1 by construction. The absolute size floors are counts of
        pixels the camera really captured, and they multiply back through it.
        """
        if self.width <= 0:  # pragma: no cover - defensive
            return 1.0
        return max(1.0, self.original_width / float(self.width))


def load_image(data: bytes, config: EngineConfig) -> LoadedImage:
    """Decode, orient, colour-convert and size-normalise the input bytes.

    Raises ``UnsupportedFormat``, ``FileTooLarge`` or ``ImageTooSmall``; the
    engine turns those into a rejected result.
    """
    if not data:
        raise UnsupportedFormat("Empty image payload.")
    if len(data) > config.max_input_bytes:
        raise FileTooLarge(f"Image is {len(data)} bytes, limit is {config.max_input_bytes}.")

    try:
        with Image.open(_io.BytesIO(data)) as img:
            fmt = (img.format or "").upper()
            if fmt not in SUPPORTED_FORMATS:
                raise UnsupportedFormat(
                    f"Unsupported image format: {fmt or 'unknown'}. "
                    "Accepted: JPEG, PNG, WebP, HEIC/HEIF."
                )

            # Multi-frame files (MPO) before anything else, so the EXIF and the
            # ICC profile we go on to read belong to the frame we measure.
            _first_frame(img)

            # EXIF next: a portrait photo stored landscape-with-a-rotation-flag
            # must be upright before we look for a face.
            img = ImageOps.exif_transpose(img) or img
            original_width, original_height = img.size

            img, source_color_space = _to_srgb(img)

            # Alpha is dropped by the RGB conversion; transparent PNGs are not a
            # case we serve, so no compositing dance.
            if img.mode != "RGB":
                img = img.convert("RGB")

            if min(img.size) < config.min_short_side:
                raise ImageTooSmall(
                    f"Shortest side is {min(img.size)}px, minimum is {config.min_short_side}px."
                )

            img, downscaled = _downscale(img, config.max_side)
            rgb = np.asarray(img, dtype=np.uint8)
    except UnidentifiedImageError as exc:
        raise UnsupportedFormat("Could not identify the image format.") from exc
    except (OSError, ValueError) as exc:
        if isinstance(exc, (UnsupportedFormat, FileTooLarge, ImageTooSmall)):
            raise
        raise UnsupportedFormat("Could not decode the image data.") from exc

    if rgb.ndim != 3 or rgb.shape[2] != 3:
        raise UnsupportedFormat("Decoded image is not 3-channel RGB.")

    height, width = rgb.shape[0], rgb.shape[1]
    return LoadedImage(
        rgb=rgb,
        width=width,
        height=height,
        source_color_space=source_color_space,
        source_format=fmt,
        original_width=original_width,
        original_height=original_height,
        downscaled=downscaled,
    )


def _first_frame(img: Image.Image) -> None:
    """Select frame 0 of a multi-frame file, in place.

    An MPO is a JPEG with more than one image in it. On current phones the extra
    frames are an HDR gain map or a depth map - never the picture the person
    took. Frame 0 is the primary image, and it is the only one we measure.

    Pillow opens at frame 0 already; seeking explicitly is what guarantees it,
    and it also reloads ``img.info`` from that frame so the ICC profile and EXIF
    read afterwards are the primary image's, not a gain map's.
    """
    if getattr(img, "n_frames", 1) <= 1:
        return
    with suppress(EOFError, OSError, ValueError):  # a malformed file is not worth failing on
        img.seek(0)


def _to_srgb(img: Image.Image) -> tuple[Image.Image, str]:
    """Convert an image to sRGB using its embedded ICC profile, if it has one.

    Rendering intent is perceptual, as specified. For the matrix/TRC profiles
    phones embed, littleCMS treats perceptual and relative colorimetric alike, so
    the choice only matters for the rare LUT profile.
    """
    raw_profile = img.info.get("icc_profile")
    if not raw_profile:
        return img, SRGB_ASSUMED

    try:
        source = ImageCms.getOpenProfile(_io.BytesIO(raw_profile))
        name = _profile_name(source)
        # Grayscale and CMYK sources exist in the wild; convert through RGB.
        working = img if img.mode in ("RGB", "RGBA") else img.convert("RGB")
        if working.mode == "RGBA":
            working = working.convert("RGB")
        converted = ImageCms.profileToProfile(
            working,
            source,
            ImageCms.createProfile("sRGB"),
            renderingIntent=ImageCms.Intent.PERCEPTUAL,
            outputMode="RGB",
        )
    except (ImageCms.PyCMSError, OSError, ValueError):
        # A broken profile is not worth failing the whole analysis over: fall
        # back to sRGB and say so, so the caller can discount the reading.
        return img, "sRGB (unreadable profile)"

    if converted is None:  # pragma: no cover - defensive, PIL returns an image
        return img, name
    return converted, name


def _profile_name(profile: Any) -> str:
    try:
        description = ImageCms.getProfileDescription(profile)
    except (ImageCms.PyCMSError, OSError):  # pragma: no cover - defensive
        return "unknown ICC profile"
    return (description or "unknown ICC profile").strip()


def _downscale(img: Image.Image, max_side: int) -> tuple[Image.Image, bool]:
    longest = max(img.size)
    if longest <= max_side:
        return img, False
    scale = max_side / float(longest)
    size = (max(1, round(img.width * scale)), max(1, round(img.height * scale)))
    # Lanczos: we are about to take medians of small regions, so resampling
    # artefacts are colour errors. Nothing cheaper is good enough.
    return img.resize(size, Image.Resampling.LANCZOS), True
