"""The sclera wedges stop short of both corners.

The landmark opening runs corner to corner. The white of the eye does not: the
caruncle - the pink fleshy mound - sits inside the inner corner, and at the
outer corner the two lid margins converge onto skin.

Why that is worse than losing a few pixels: ``select_sclera`` takes its a*b*
reference from the median of whatever it is handed. While corner skin is a
minority the filter removes it, but once it is the majority the reference itself
lands on skin, and the filter then keeps the skin and throws out the sclera. The
sample that comes back is not brighter than the skin because it *is* the skin -
which is exactly the verdict the first two live frames returned.
"""

from __future__ import annotations

import cv2
import numpy as np

from color_engine.arrays import ImageArray, MaskArray
from color_engine.config import EngineConfig
from color_engine.detect import Detection
from color_engine.sampling import select_sclera
from color_engine.zones import (
    LEFT_EYE_OPENING,
    RIGHT_EYE_CORNERS,
    RIGHT_EYE_OPENING,
    build_zones,
)

from .test_zones import _ellipse, _synthetic_detection

SKIN = (236, 200, 185)
SCLERA = (238, 236, 232)
CARUNCLE = (222, 160, 158)
IRIS = (110, 130, 140)


def _squint(half_height: float) -> Detection:
    """The synthetic face with a fissure of the given half-height."""
    detection = _synthetic_detection()
    placements = []
    placements += _ellipse(RIGHT_EYE_OPENING, (300, 335), (45, half_height))
    placements += _ellipse(LEFT_EYE_OPENING, (500, 335), (45, half_height))
    for index, (x, y) in placements:
        detection.landmarks[index] = (x, y)

    return detection


def _eye(
    detection: Detection, white_half_width: int, height_ratio: float
) -> tuple[ImageArray, MaskArray]:
    """Paint an eye whose visible white is narrower than the landmark opening.

    Returns the image and a truth mask of where the white actually is.
    """
    size = detection.category_mask.shape[0]
    image = np.full((size, size, 3), SKIN, dtype=np.uint8)
    truth = np.zeros((size, size), dtype=np.uint8)

    for centre in ((300, 335), (500, 335)):
        opening = detection.landmarks[list(RIGHT_EYE_OPENING)]
        half_height = float(opening[:, 1].max() - opening[:, 1].min()) / 2.0
        ball = max(2, int(half_height * height_ratio))
        cv2.ellipse(image, centre, (white_half_width, ball), 0, 0, 360, SCLERA, thickness=-1)
        cv2.ellipse(truth, centre, (white_half_width, ball), 0, 0, 360, 1, thickness=-1)
        # The caruncle, inside the inner corner.
        inward = -28 if centre[0] < 400 else 28
        cv2.circle(image, (centre[0] + inward, centre[1]), 7, CARUNCLE, thickness=-1)
        cv2.circle(truth, (centre[0] + inward, centre[1]), 7, 0, thickness=-1)
        cv2.circle(image, centre, 18, IRIS, thickness=-1)
        cv2.circle(truth, centre, 18, 0, thickness=-1)

    return image, truth


def _reach(detection: Detection, config: EngineConfig) -> tuple[float, float, float, float]:
    """How far the wedge mask reaches along the eye axis, against the corners."""
    zones = build_zones(detection, config)
    zone = zones.get("sclera_right")
    assert zone is not None

    corners = detection.landmarks[list(RIGHT_EYE_CORNERS)].astype(np.float64)
    centre = detection.landmarks[468].astype(np.float64)
    axis = corners[1] - corners[0]
    axis /= np.linalg.norm(axis)

    ys, xs = np.nonzero(zone.mask)
    along = (np.stack([xs, ys], axis=1).astype(np.float64) - centre) @ axis
    corner_along = (corners - centre) @ axis

    return (
        float(along.min()),
        float(along.max()),
        float(corner_along[0]),
        float(corner_along[1]),
    )


def test_the_wedges_stop_short_of_the_inner_corner(config: EngineConfig) -> None:
    inner_reach, _, inner_corner, _ = _reach(_squint(22.0), config)

    # Both are negative; "stops short" means the mask does not go as far out.
    assert abs(inner_reach) < abs(inner_corner)


def test_the_wedges_stop_short_of_the_outer_corner(config: EngineConfig) -> None:
    _, outer_reach, _, outer_corner = _reach(_squint(22.0), config)

    assert outer_reach < outer_corner


def test_the_inner_corner_is_cut_back_further_than_the_outer(config: EngineConfig) -> None:
    # The caruncle is a structure with real size; the outer join is an edge.
    assert config.sclera_inner_corner_cut > config.sclera_outer_corner_cut


def test_a_narrow_eye_still_produces_a_sample(config: EngineConfig) -> None:
    # The cut must not be so deep that a squint has nothing left to measure.
    detection = _squint(12.0)
    image, _ = _eye(detection, 40, 1.0)
    zone = build_zones(detection, config).get("sclera_right")
    assert zone is not None

    assert select_sclera(image, zone.mask, config).sample is not None


def test_the_sample_stays_off_the_corners_when_the_white_is_narrow(
    config: EngineConfig,
) -> None:
    # The case that inverted the a*b* filter: visible white reaching only part
    # of the way to the corners. Before the cut, more than half the sampled
    # pixels were skin.
    detection = _squint(22.0)
    image, truth = _eye(detection, 34, 1.0)
    zone = build_zones(detection, config).get("sclera_right")
    assert zone is not None

    picked = select_sclera(image, zone.mask, config)
    assert picked.kept is not None
    kept = picked.kept > 0
    off = int((kept & (truth == 0)).sum()) / max(int(kept.sum()), 1)

    assert off == 0.0, f"{off:.1%} of the sample is not sclera"


def test_the_sample_is_the_white_and_not_the_skin(config: EngineConfig) -> None:
    detection = _squint(22.0)
    image, _ = _eye(detection, 34, 1.0)
    zone = build_zones(detection, config).get("sclera_right")
    assert zone is not None

    sample = select_sclera(image, zone.mask, config).sample
    assert sample is not None
    # Skin here is L* ~84, the painted white ~93. Landing on the skin is the
    # failure this whole file is about, and it is visible in one number.
    assert sample.L > 88.0
