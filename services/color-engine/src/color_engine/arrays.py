"""Named numpy array types.

An imaging pipeline moves several kinds of array around and they are easy to
confuse: an RGB image, a binary mask, landmark coordinates, CIELAB values. These
aliases say which is which in the signatures, and keep mypy strict happy about
generic parameters at the same time.
"""

from __future__ import annotations

from typing import Any

import numpy as np
from numpy.typing import NDArray

ImageArray = NDArray[np.uint8]
"""(H, W, 3) uint8, sRGB."""

MaskArray = NDArray[np.uint8]
"""(H, W) uint8. Binary masks are 0 or 255; the segmenter's category mask holds
``SegmentClass`` values."""

PointArray = NDArray[np.float32]
"""(N, 2) float32 pixel coordinates - landmarks and polygons."""

FloatArray = NDArray[np.float64]
"""Measurements: CIELAB values, matrices, anything computed."""

AnyArray = NDArray[Any]
"""Where the dtype genuinely varies (helpers that take uint8 or float alike)."""

Shape2D = tuple[int, int]
"""(height, width)."""
