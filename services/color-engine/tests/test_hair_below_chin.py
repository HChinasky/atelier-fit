"""A dark top under the hair is not hair.

The segmenter labels the clothes under a head of hair as HAIR when they are dark
enough. On a frame of a light-brown head in a black T-shirt the hair mean came out
at L* 25, near black, and the depth axis read 'deep'. The hair zone stops at the
chin.
"""

from __future__ import annotations

import numpy as np

from color_engine.config import EngineConfig
from color_engine.detect import SegmentClass
from color_engine.sampling import sample_zones
from color_engine.zones import build_zones

from .test_sclera_pooling import _face
from .test_zones import _synthetic_detection

BROWN = (150, 112, 86)
BLACK_TOP = (24, 24, 26)


def _head_in_a_black_top():
    detection = _synthetic_detection()
    image = _face(detection)
    image[:200, :] = BROWN
    # Hair at the two sides only, as on a real head with a parting: the top is
    # then the larger part of what the segmenter calls hair, as it was on the
    # frame this is about (the median follows the larger part).
    detection.category_mask[:200, 150:650] = SegmentClass.BACKGROUND
    # The fixture's chin is at y = 670: everything under it is the top, which
    # the segmenter calls hair.
    detection.category_mask[700:, :] = SegmentClass.HAIR
    image[700:, :] = BLACK_TOP
    return detection, image


def test_nothing_below_the_chin_is_in_the_hair_zone(config: EngineConfig) -> None:
    detection, _ = _head_in_a_black_top()
    _, top, _, height = detection.face_bbox

    hair = build_zones(detection, config).get("hair")

    assert hair is not None
    rows = np.flatnonzero((hair.mask > 0).any(axis=1))
    assert rows.max() < top + height


def test_the_top_stays_out_of_the_hair_colour(config: EngineConfig) -> None:
    detection, image = _head_in_a_black_top()

    hair = sample_zones(image, build_zones(detection, config), config).samples.hair

    assert hair is not None
    # Brown is L* ~48; the top is L* ~9. With the top in, the mean falls under 35.
    assert hair.L > 40.0


def test_hair_that_hangs_past_the_chin_is_simply_ignored(config: EngineConfig) -> None:
    detection, image = _head_in_a_black_top()
    detection.category_mask[700:, :] = SegmentClass.HAIR
    image[700:, :] = BROWN  # long hair this time: the same colour as the rest

    hair = sample_zones(image, build_zones(detection, config), config).samples.hair

    assert hair is not None
    assert 40.0 < hair.L < 60.0
