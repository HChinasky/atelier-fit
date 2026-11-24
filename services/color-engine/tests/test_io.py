"""Decoding: colour management, orientation, formats and size limits."""

from __future__ import annotations

import io

import colour
import numpy as np
import pytest
from PIL import Image

from color_engine.arrays import AnyArray, FloatArray
from color_engine.config import EngineConfig
from color_engine.errors import FileTooLarge, ImageTooSmall, UnsupportedFormat
from color_engine.io import SRGB_ASSUMED, load_image

from .support.icc import display_p3_profile_bytes, expected_srgb

# Saturated enough that reading it as sRGB instead of P3 is an obvious error,
# but inside both gamuts so nothing is clipped.
P3_PATCH = (210, 90, 70)


def _encode(img: Image.Image, fmt: str = "PNG", **kwargs: object) -> bytes:
    buffer = io.BytesIO()
    img.save(buffer, format=fmt, **kwargs)
    return buffer.getvalue()


def _solid(color: tuple[int, int, int], size: tuple[int, int] = (800, 600)) -> Image.Image:
    return Image.new("RGB", size, color)


def _lab(rgb: AnyArray) -> FloatArray:
    values = np.asarray(rgb, dtype=np.float64) / 255.0
    return np.asarray(colour.XYZ_to_Lab(colour.sRGB_to_XYZ(values)), dtype=np.float64)


def _delta_e(left: AnyArray, right: AnyArray) -> float:
    return float(colour.delta_E(_lab(left), _lab(right), method="CIE 2000"))


def test_embedded_p3_profile_is_converted_to_srgb(config: EngineConfig) -> None:
    data = _encode(_solid(P3_PATCH), icc_profile=display_p3_profile_bytes())

    loaded = load_image(data, config)
    measured = loaded.rgb[10, 10].astype(np.float64)
    reference = expected_srgb(P3_PATCH)

    assert "Display P3" in (loaded.source_color_space or "")
    # The ICC path and the independent colour-science computation agree...
    assert _delta_e(measured, reference) < 2.0
    # ...and both differ clearly from having ignored the profile, which is the
    # bug this test exists to catch.
    assert _delta_e(np.asarray(P3_PATCH, dtype=np.float64), reference) > 2.0


def test_untagged_image_is_assumed_srgb_and_left_alone(config: EngineConfig) -> None:
    data = _encode(_solid(P3_PATCH))

    loaded = load_image(data, config)

    assert loaded.source_color_space == SRGB_ASSUMED
    assert tuple(int(v) for v in loaded.rgb[10, 10]) == P3_PATCH


def test_broken_profile_falls_back_to_srgb(config: EngineConfig) -> None:
    data = _encode(_solid(P3_PATCH), icc_profile=b"not an icc profile at all")

    loaded = load_image(data, config)

    assert loaded.source_color_space == "sRGB (unreadable profile)"
    assert tuple(int(v) for v in loaded.rgb[10, 10]) == P3_PATCH


def test_exif_orientation_is_applied(config: EngineConfig) -> None:
    # A wide image tagged "rotate 90" must come back tall, with the marker
    # stripe moved accordingly.
    img = Image.new("RGB", (900, 600), (20, 20, 20))
    img.paste(Image.new("RGB", (900, 60), (240, 240, 240)), (0, 0))  # stripe along the top
    exif = img.getexif()
    exif[274] = 6  # orientation: rotate 90 CW
    data = _encode(img, fmt="JPEG", exif=exif, quality=95)

    loaded = load_image(data, config)

    assert loaded.height > loaded.width
    # Orientation 6 turns the stored image 90 degrees clockwise, so the stripe
    # that was along the top ends up down the right-hand edge.
    assert loaded.rgb[loaded.height // 2, loaded.width - 5].mean() > 200
    assert loaded.rgb[loaded.height // 2, 5].mean() < 60


def test_large_image_is_downscaled_to_max_side(config: EngineConfig) -> None:
    data = _encode(_solid((128, 128, 128), size=(4000, 3000)), fmt="JPEG", quality=80)

    loaded = load_image(data, config)

    assert max(loaded.width, loaded.height) == config.max_side
    assert loaded.downscaled is True
    assert (loaded.original_width, loaded.original_height) == (4000, 3000)


def test_small_image_is_rejected(config: EngineConfig) -> None:
    data = _encode(_solid((128, 128, 128), size=(640, 300)))

    with pytest.raises(ImageTooSmall):
        load_image(data, config)


def test_oversized_payload_is_rejected_before_decoding(config: EngineConfig) -> None:
    tiny = EngineConfig(max_input_bytes=1024)
    data = _encode(_solid((128, 128, 128), size=(2000, 2000)), fmt="JPEG")

    assert len(data) > 1024
    with pytest.raises(FileTooLarge):
        load_image(data, tiny)


def test_unsupported_format_is_rejected(config: EngineConfig) -> None:
    data = _encode(_solid((128, 128, 128)), fmt="TIFF")

    with pytest.raises(UnsupportedFormat):
        load_image(data, config)


def test_empty_payload_is_rejected(config: EngineConfig) -> None:
    with pytest.raises(UnsupportedFormat):
        load_image(b"", config)


def test_garbage_bytes_are_rejected(config: EngineConfig) -> None:
    with pytest.raises(UnsupportedFormat):
        load_image(b"\x89PNG\r\n\x1a\n" + b"\x00" * 200, config)


def test_webp_is_supported(config: EngineConfig) -> None:
    data = _encode(_solid((180, 120, 90)), fmt="WEBP", lossless=True)

    loaded = load_image(data, config)

    assert loaded.source_format == "WEBP"
    assert tuple(int(v) for v in loaded.rgb[10, 10]) == (180, 120, 90)


def test_heic_is_supported(config: EngineConfig) -> None:
    buffer = io.BytesIO()
    try:
        _solid((180, 120, 90)).save(buffer, format="HEIF", quality=95)
    except (OSError, ValueError, KeyError) as exc:  # pragma: no cover - build dependent
        pytest.skip(f"pillow-heif has no HEIF encoder in this build: {exc}")

    loaded = load_image(buffer.getvalue(), config)

    assert loaded.source_format in {"HEIF", "HEIC"}
    assert loaded.width == 800
    # HEIF is lossy: check the colour is close, not identical.
    assert _delta_e(loaded.rgb[400, 300].astype(np.float64), np.asarray((180, 120, 90.0))) < 3.0


def test_rgba_png_loses_its_alpha_without_compositing(config: EngineConfig) -> None:
    img = Image.new("RGBA", (800, 600), (180, 120, 90, 128))

    loaded = load_image(_encode(img), config)

    assert loaded.rgb.shape == (600, 800, 3)
    assert tuple(int(v) for v in loaded.rgb[10, 10]) == (180, 120, 90)


def test_mpo_is_read_as_its_first_frame(config: EngineConfig) -> None:
    """A phone JPEG with an HDR gain map opens as MPO. The gain map is not the
    photo; frame 0 is."""
    primary = _solid((210, 90, 70))
    gain_map = _solid((20, 220, 40))  # nothing like the primary, so a mix-up shows
    buffer = io.BytesIO()
    primary.save(buffer, format="MPO", save_all=True, append_images=[gain_map], quality=95)

    loaded = load_image(buffer.getvalue(), config)

    assert loaded.source_format == "MPO"
    assert _delta_e(loaded.rgb[300, 400].astype(np.float64), np.asarray((210.0, 90.0, 70.0))) < 3.0


def test_mpo_icc_and_exif_come_from_the_first_frame(config: EngineConfig) -> None:
    """Seeking to frame 0 reloads info; the profile and rotation we honour have
    to be the primary image's."""
    primary = _solid((210, 90, 70), size=(900, 600))
    primary.paste(Image.new("RGB", (900, 60), (240, 240, 240)), (0, 0))
    exif = primary.getexif()
    exif[274] = 6  # rotate 90 CW
    buffer = io.BytesIO()
    primary.save(
        buffer,
        format="MPO",
        save_all=True,
        append_images=[_solid((20, 220, 40), size=(900, 600))],
        exif=exif,
        icc_profile=display_p3_profile_bytes(),
        quality=95,
    )

    loaded = load_image(buffer.getvalue(), config)

    assert "Display P3" in (loaded.source_color_space or "")
    assert loaded.height > loaded.width, "the primary frame's EXIF rotation was not applied"
