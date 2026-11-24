"""Debug artifacts: the zone specs, the overlay, labels and crops.

All synthetic - this exercises the machinery the report is built from without
needing a model or a face.
"""

from __future__ import annotations

import numpy as np

from color_engine.arrays import ImageArray
from color_engine.config import EngineConfig
from color_engine.debug import (
    DebugArtifacts,
    LandmarkLabels,
    render_overlay,
    render_zone_crop,
)
from color_engine.zones import LANDMARKS_IN_USE, ZONE_SPEC_BY_NAME, ZONE_SPECS, build_zones

from .test_zones import _synthetic_detection


def _artifacts(config: EngineConfig) -> tuple[ImageArray, DebugArtifacts]:
    detection = _synthetic_detection()
    debug = DebugArtifacts()
    debug.landmarks = detection.landmarks
    debug.face_bbox = detection.face_bbox
    debug.face_count = 1
    debug.category_mask = detection.category_mask
    debug.record_zones(build_zones(detection, config))
    size = detection.category_mask.shape[0]
    rgb = np.full((size, size, 3), 128, dtype=np.uint8)
    return rgb, debug


def test_every_zone_has_a_spec(config: EngineConfig) -> None:
    built = build_zones(_synthetic_detection(), config)

    for name, zone in built.zones.items():
        assert zone.spec is not None, f"{name} has no ZoneSpec"
        assert zone.spec.zone == name
        assert zone.spec.note


def test_specs_cover_exactly_the_zones_that_exist(config: EngineConfig) -> None:
    built = build_zones(_synthetic_detection(), config)

    assert set(ZONE_SPEC_BY_NAME) == set(built.zones)


def test_landmarks_in_use_is_the_union_of_the_index_sets() -> None:
    expected = {index for spec in ZONE_SPECS for index in spec.indices}

    assert expected == LANDMARKS_IN_USE
    assert len(LANDMARKS_IN_USE) > 40
    assert max(LANDMARKS_IN_USE) < 478


def test_record_zones_keeps_pixel_counts_and_specs(config: EngineConfig) -> None:
    _, debug = _artifacts(config)

    assert debug.zone_pixels["forehead"] > 0
    assert debug.zone_specs["forehead"].constant == "FOREHEAD_POINTS"
    assert debug.face_area > 0


def test_overlay_paints_the_zones(config: EngineConfig) -> None:
    rgb, debug = _artifacts(config)

    out = render_overlay(rgb, debug)

    assert out.shape == rgb.shape
    assert not np.array_equal(out, rgb), "nothing was drawn"


def test_landmark_labels_change_the_overlay_and_scale_with_the_setting(
    config: EngineConfig,
) -> None:
    rgb, debug = _artifacts(config)

    plain = render_overlay(rgb, debug, LandmarkLabels.NONE)
    zones_labelled = render_overlay(rgb, debug, LandmarkLabels.ZONES)
    all_labelled = render_overlay(rgb, debug, LandmarkLabels.ALL)

    assert not np.array_equal(plain, zones_labelled)
    # 'all' must mark strictly more of the frame than 'zones'.
    changed_zones = int(np.count_nonzero(np.any(zones_labelled != plain, axis=2)))
    changed_all = int(np.count_nonzero(np.any(all_labelled != plain, axis=2)))
    assert changed_all > changed_zones > 0


def test_zone_crop_is_zoomed_in_on_the_zone(config: EngineConfig) -> None:
    rgb, debug = _artifacts(config)
    overlaid = render_overlay(rgb, debug)

    crop = render_zone_crop(overlaid, debug, "iris_right")

    assert crop is not None
    assert crop.shape[0] < overlaid.shape[0], "crop is not a window"
    assert min(crop.shape[:2]) >= 240, "crop was not enlarged to a readable size"


def test_zone_crop_is_none_for_a_zone_with_no_pixels(config: EngineConfig) -> None:
    rgb, debug = _artifacts(config)
    debug.zone_masks["forehead"] = np.zeros_like(debug.zone_masks["forehead"])

    assert render_zone_crop(render_overlay(rgb, debug), debug, "forehead") is None
    assert render_zone_crop(render_overlay(rgb, debug), debug, "not_a_zone") is None
