"""Zone building, on a synthetic face.

No MediaPipe here: a ``Detection`` is just landmarks plus a category mask, so we
can hand-build one and check the geometry - that the segmenter class really does
clip the polygons, that the iris comes out as an annulus, that the sclera does
not overlap it, and that morphology scales with the face instead of the image.
"""

from __future__ import annotations

import numpy as np

from color_engine.config import EngineConfig
from color_engine.detect import LANDMARK_COUNT, Detection, HeadPose, SegmentClass
from color_engine.zones import (
    FOREHEAD_POINTS,
    LEFT_CHEEK_POINTS,
    LEFT_EYE_OPENING,
    LEFT_IRIS,
    RIGHT_CHEEK_POINTS,
    RIGHT_EYE_OPENING,
    RIGHT_IRIS,
    build_zones,
)


def _ellipse(
    indices: tuple[int, ...], centre: tuple[float, float], radii: tuple[float, float]
) -> list[tuple[int, tuple[float, float]]]:
    angles = np.linspace(0.0, 2.0 * np.pi, len(indices), endpoint=False)
    return [
        (idx, (centre[0] + radii[0] * float(np.cos(a)), centre[1] + radii[1] * float(np.sin(a))))
        for idx, a in zip(indices, angles, strict=True)
    ]


def _synthetic_detection(scale: float = 1.0) -> Detection:
    """A 800x800 (times ``scale``) frontal face: hair band on top, oval of face
    skin, two eyes with irises."""
    size = int(800 * scale)
    landmarks = np.zeros((LANDMARK_COUNT, 2), dtype=np.float32)
    landmarks[:] = (400 * scale, 400 * scale)

    placements: list[tuple[int, tuple[float, float]]] = []
    placements += _ellipse(FOREHEAD_POINTS, (400, 240), (150, 55))
    placements += _ellipse(RIGHT_CHEEK_POINTS, (285, 430), (60, 55))
    placements += _ellipse(LEFT_CHEEK_POINTS, (515, 430), (60, 55))
    placements += _ellipse(RIGHT_EYE_OPENING, (300, 335), (45, 22))
    placements += _ellipse(LEFT_EYE_OPENING, (500, 335), (45, 22))
    for iris, centre in ((RIGHT_IRIS, (300.0, 335.0)), (LEFT_IRIS, (500.0, 335.0))):
        landmarks[iris[0]] = (centre[0] * scale, centre[1] * scale)
        placements += _ellipse(iris[1:], centre, (18, 18))
    for index, (x, y) in placements:
        landmarks[index] = (x * scale, y * scale)

    category_mask = np.zeros((size, size), dtype=np.uint8)
    yy, xx = np.mgrid[0:size, 0:size]
    face = ((xx - 400 * scale) / (190 * scale)) ** 2 + ((yy - 420 * scale) / (250 * scale)) ** 2
    category_mask[face <= 1.0] = SegmentClass.FACE_SKIN
    # Hair: a band across the top, overlapping the upper part of the face oval
    # so the forehead polygon has something to be clipped against.
    category_mask[: int(200 * scale), :] = SegmentClass.HAIR

    bbox = (210.0 * scale, 170.0 * scale, 380.0 * scale, 500.0 * scale)
    return Detection(
        face_count=1,
        landmarks=landmarks,
        landmarks_z=np.zeros((LANDMARK_COUNT,), dtype=np.float32),
        face_bbox=bbox,
        face_width=bbox[2],
        head_pose=HeadPose(0.0, 0.0, 0.0, 0.0, "matrix"),
        category_mask=category_mask,
    )


def test_every_zone_is_built(config: EngineConfig) -> None:
    built = build_zones(_synthetic_detection(), config)

    assert set(built.zones) == {
        "left_cheek",
        "right_cheek",
        "forehead",
        "skin",
        "hair",
        "iris_left",
        "iris_right",
        "iris",
        "sclera_left",
        "sclera_right",
        "sclera",
        "background",
    }
    assert built.insufficient == []


def test_the_background_zone_is_the_scene_behind_the_person(config: EngineConfig) -> None:
    """White balance's fallback reference. Everything the segmenter called
    background, eroded off the outline - and nothing the person is wearing."""
    detection = _synthetic_detection()
    detection.category_mask[600:, :] = SegmentClass.CLOTHES

    built = build_zones(detection, config)

    zone = built.get("background")
    assert zone is not None
    assert zone.pixels > 0
    clothes = detection.category_mask == SegmentClass.CLOTHES
    assert not np.any(zone.mask[clothes]), "clothes leaked into the scene estimate"
    face = detection.category_mask == SegmentClass.FACE_SKIN
    assert not np.any(zone.mask[face])


def test_skin_zones_stay_inside_the_face_skin_class(config: EngineConfig) -> None:
    detection = _synthetic_detection()
    built = build_zones(detection, config)
    face_skin = detection.category_mask == SegmentClass.FACE_SKIN

    for name in ("left_cheek", "right_cheek", "forehead", "skin"):
        zone = built.zones[name]
        assert zone.pixels > 0
        assert not np.any((zone.mask > 0) & ~face_skin), f"{name} leaked outside face skin"


def test_forehead_is_clipped_by_the_hair_class(config: EngineConfig) -> None:
    """The polygon runs up into the hair band; the intersection must cut it."""
    detection = _synthetic_detection()
    built = build_zones(detection, config)
    hair = detection.category_mask == SegmentClass.HAIR

    assert not np.any((built.zones["forehead"].mask > 0) & hair)


def test_skin_is_the_union_of_its_three_parts(config: EngineConfig) -> None:
    built = build_zones(_synthetic_detection(), config)

    union = np.zeros_like(built.zones["skin"].mask)
    for name in ("left_cheek", "right_cheek", "forehead"):
        union |= built.zones[name].mask

    assert np.array_equal(built.zones["skin"].mask, union)


def test_iris_is_an_annulus_with_the_pupil_removed(config: EngineConfig) -> None:
    detection = _synthetic_detection()
    built = build_zones(detection, config)

    iris = built.zones["iris_right"].mask
    centre = (335, 300)  # row, col
    assert iris[centre] == 0, "pupil was not excluded"
    assert iris[centre[0], centre[1] + 14] > 0, "iris ring is missing"
    # And it stops short of the measured radius (18px) by iris_outer_ratio.
    assert iris[centre[0], centre[1] + 18] == 0


def test_sclera_never_overlaps_the_iris(config: EngineConfig) -> None:
    built = build_zones(_synthetic_detection(), config)

    for side in ("left", "right"):
        overlap = (built.zones[f"sclera_{side}"].mask > 0) & (built.zones[f"iris_{side}"].mask > 0)
        assert not overlap.any()


def test_hair_zone_is_eroded_away_from_its_border(config: EngineConfig) -> None:
    detection = _synthetic_detection()
    built = build_zones(detection, config)

    raw = int(np.count_nonzero(detection.category_mask == SegmentClass.HAIR))
    assert 0 < built.zones["hair"].pixels < raw


def test_small_zones_are_flagged_rather_than_raising(config: EngineConfig) -> None:
    detection = _synthetic_detection()
    # Wipe the segmentation: the skin polygons now intersect with nothing.
    blank = Detection(
        face_count=detection.face_count,
        landmarks=detection.landmarks,
        landmarks_z=detection.landmarks_z,
        face_bbox=detection.face_bbox,
        face_width=detection.face_width,
        head_pose=detection.head_pose,
        category_mask=np.zeros_like(detection.category_mask),
    )

    built = build_zones(blank, config)

    assert built.get("skin") is None
    assert "skin" in built.insufficient
    assert "hair" in built.insufficient
    # The eyes are geometric, not segmentation-driven, so they still work.
    assert built.get("iris") is not None


def test_morphology_scales_with_the_face_not_the_pixels(config: EngineConfig) -> None:
    """The same face at twice the resolution must keep the same zone proportions."""
    small = build_zones(_synthetic_detection(1.0), config)
    large = build_zones(_synthetic_detection(2.0), config)

    for name in ("skin", "hair", "iris"):
        ratio = large.zones[name].pixels / (small.zones[name].pixels * 4.0)
        assert 0.9 < ratio < 1.1, f"{name} changed shape with resolution: {ratio:.3f}"
