"""Headphones, hats and bands are not hair.

A live frame of somebody in headphones measured hair at L* 12.2, C* 3.0 -
near-black and near-neutral, which is the plastic and not the person. The
segmenter has a class for accessories and the hair mask ran right up to its
edge, so the band where the segmenter is least certain lay exactly along the
boundary that matters, and the pixels it got wrong there are dark and neutral.

Hair carries half of the depth axis, so this is not a small error.
"""

from __future__ import annotations

import cv2
import numpy as np

from color_engine.arrays import ImageArray
from color_engine.config import EngineConfig
from color_engine.detect import Detection, SegmentClass
from color_engine.sampling import sample_zones
from color_engine.zones import HAIR_OCCLUDED, build_zones

from .test_sclera_pooling import _face
from .test_zones import _synthetic_detection

HEADPHONE = (28, 28, 30)


def _with_accessory(coverage: float) -> tuple[Detection, ImageArray]:
    """The synthetic face wearing a band across the given share of its hair."""
    detection = _synthetic_detection()
    image = _face(detection)

    hair = detection.category_mask == SegmentClass.HAIR
    rows = np.flatnonzero(hair.any(axis=1))
    height = int(round(len(rows) * coverage))
    if height:
        band = slice(rows[-1] - height + 1, rows[-1] + 1)
        detection.category_mask[band, :][hair[band, :]] = SegmentClass.OTHERS
        painted = np.zeros_like(hair)
        painted[band, :] = True
        image[painted] = HEADPHONE

    return detection, image


def test_accessory_pixels_do_not_reach_the_hair_zone(config: EngineConfig) -> None:
    detection, _ = _with_accessory(0.25)
    accessories = detection.category_mask == SegmentClass.OTHERS
    assert accessories.any(), "the fixture painted no accessory"

    hair = build_zones(detection, config).get("hair")

    assert hair is not None
    assert not (hair.mask > 0)[accessories].any()


def test_the_neighbourhood_of_an_accessory_goes_too(config: EngineConfig) -> None:
    # The edge is where a segmenter is least sure, and what it gets wrong there
    # is dark and neutral - which is to say it looks like hair.
    detection, _ = _with_accessory(0.25)
    accessories = (detection.category_mask == SegmentClass.OTHERS).astype(np.uint8)
    near = cv2.dilate(accessories, np.ones((9, 9), np.uint8)) > 0

    hair = build_zones(detection, config).get("hair")

    assert hair is not None
    kept_near = int((hair.mask > 0)[near].sum())
    assert kept_near < int(near.sum()) * 0.5


def test_the_accessory_colour_stays_out_of_the_measurement(config: EngineConfig) -> None:
    detection, image = _with_accessory(0.25)
    zones = build_zones(detection, config)

    hair = sample_zones(image, zones, config).samples.hair

    assert hair is not None
    # The band is L* ~11. Landing anywhere near it means the plastic got in.
    assert hair.L > 25.0


def test_hair_under_a_band_is_still_measured(config: EngineConfig) -> None:
    # A hairband across a quarter of the hair is a normal photograph, not a
    # refusal - there is plenty of hair left.
    detection, image = _with_accessory(0.25)
    zones = build_zones(detection, config)

    assert zones.reasons.get("hair") is None
    assert sample_zones(image, zones, config).samples.hair is not None


def test_a_face_with_no_accessories_is_untouched(config: EngineConfig) -> None:
    plain = build_zones(_synthetic_detection(), config).get("hair")
    assert plain is not None

    detection, _ = _with_accessory(0.0)

    unchanged = build_zones(detection, config).get("hair")
    assert unchanged is not None
    assert np.array_equal(plain.mask, unchanged.mask)


def test_headphones_over_most_of_the_hair_are_reported_not_measured(
    config: EngineConfig,
) -> None:
    detection, _ = _with_accessory(0.8)

    zones = build_zones(detection, config)

    assert zones.reasons.get("hair") == HAIR_OCCLUDED


def test_the_reason_reaches_the_contract(config: EngineConfig) -> None:
    from color_engine.sampling import zone_statuses

    detection, image = _with_accessory(0.8)
    zones = build_zones(detection, config)

    statuses = zone_statuses(zones, sample_zones(image, zones, config))

    assert not statuses["hair"].usable
    assert statuses["hair"].reason == HAIR_OCCLUDED
