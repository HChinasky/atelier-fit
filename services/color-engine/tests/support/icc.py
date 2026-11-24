"""Build a real Display P3 ICC profile in memory.

PIL can only *create* sRGB/Lab/XYZ profiles, and committing a binary .icc blob
to test a colour conversion is opaque. So we write a minimal, valid ICC v2.4
matrix/TRC profile here: the primaries are Display P3's, Bradford-adapted to the
D50 profile connection space exactly as the spec requires, and the tone curve is
a plain gamma.

That gives the io test a genuine non-sRGB input whose correct sRGB rendering can
be computed independently with colour-science.
"""

from __future__ import annotations

import struct
from collections.abc import Iterable

import colour
import numpy as np

from color_engine.arrays import FloatArray

# The PCS is always D50 in ICC.
D50_XYZ = (0.9642, 1.0, 0.8249)
PROFILE_GAMMA = 2.2


def _s15f16(value: float) -> bytes:
    return struct.pack(">i", round(value * 65536.0))


def _xyz_tag(xyz: Iterable[float]) -> bytes:
    body = b"XYZ " + b"\x00" * 4
    for component in xyz:
        body += _s15f16(float(component))
    return body


def _curve_tag(gamma: float) -> bytes:
    # curveType with count == 1: the single uint16 is a u8Fixed8 gamma value.
    return b"curv" + b"\x00" * 4 + struct.pack(">I", 1) + struct.pack(">H", round(gamma * 256))


def _text_description_tag(text: str) -> bytes:
    ascii_bytes = text.encode("ascii") + b"\x00"
    body = b"desc" + b"\x00" * 4
    body += struct.pack(">I", len(ascii_bytes)) + ascii_bytes
    body += struct.pack(">I", 0)  # unicode language code
    body += struct.pack(">I", 0)  # unicode count
    body += struct.pack(">H", 0)  # scriptcode code
    body += struct.pack(">B", 0)  # scriptcode count
    body += b"\x00" * 67  # macintosh description buffer
    return body


def _text_tag(text: str) -> bytes:
    return b"text" + b"\x00" * 4 + text.encode("ascii") + b"\x00"


def _pad(data: bytes) -> bytes:
    remainder = len(data) % 4
    return data if remainder == 0 else data + b"\x00" * (4 - remainder)


def display_p3_matrix_d50() -> FloatArray:
    """Display P3 RGB->XYZ, chromatically adapted from D65 to the D50 PCS."""
    colourspace = colour.RGB_COLOURSPACES["Display P3"]
    adaptation = colour.adaptation.matrix_chromatic_adaptation_VonKries(
        colour.xy_to_XYZ(colourspace.whitepoint),
        np.asarray(D50_XYZ),
        transform="Bradford",
    )
    return np.asarray(adaptation @ colourspace.matrix_RGB_to_XYZ, dtype=np.float64)


def display_p3_profile_bytes(description: str = "Display P3 (test)") -> bytes:
    """A valid ICC v2.4 RGB matrix/TRC profile describing Display P3."""
    matrix = display_p3_matrix_d50()
    curve = _curve_tag(PROFILE_GAMMA)

    tags: list[tuple[bytes, bytes]] = [
        (b"desc", _text_description_tag(description)),
        (b"wtpt", _xyz_tag(D50_XYZ)),
        (b"rXYZ", _xyz_tag(matrix[:, 0])),
        (b"gXYZ", _xyz_tag(matrix[:, 1])),
        (b"bXYZ", _xyz_tag(matrix[:, 2])),
        (b"rTRC", curve),
        (b"gTRC", curve),
        (b"bTRC", curve),
        (b"cprt", _text_tag("Public domain test profile")),
    ]

    header_size = 128
    table_size = 4 + 12 * len(tags)
    offset = header_size + table_size

    table = struct.pack(">I", len(tags))
    body = b""
    for signature, data in tags:
        padded = _pad(data)
        table += signature + struct.pack(">II", offset, len(data))
        body += padded
        offset += len(padded)

    header = bytearray(header_size)
    header[4:8] = b"\x00" * 4  # preferred CMM: none
    header[8:12] = struct.pack(">I", 0x02400000)  # version 2.4
    header[12:16] = b"mntr"
    header[16:20] = b"RGB "
    header[20:24] = b"XYZ "
    header[24:36] = struct.pack(">6H", 2026, 1, 1, 0, 0, 0)
    header[36:40] = b"acsp"
    header[64:68] = struct.pack(">I", 0)  # perceptual rendering intent
    header[68:80] = _s15f16(D50_XYZ[0]) + _s15f16(D50_XYZ[1]) + _s15f16(D50_XYZ[2])

    profile = bytes(header) + table + body
    return struct.pack(">I", len(profile)) + profile[4:]


def expected_srgb(p3_rgb: tuple[int, int, int]) -> FloatArray:
    """What a P3 pixel should become in sRGB, computed without going near ICC.

    Uses the same gamma the test profile declares, so the only things that can
    differ from the ICC path are 8-bit quantisation and matrix rounding.
    """
    linear = (np.asarray(p3_rgb, dtype=np.float64) / 255.0) ** PROFILE_GAMMA
    colourspace = colour.RGB_COLOURSPACES["Display P3"]
    xyz = colourspace.matrix_RGB_to_XYZ @ linear
    srgb = colour.XYZ_to_sRGB(xyz)
    return np.asarray(np.clip(srgb, 0.0, 1.0) * 255.0, dtype=np.float64)
