"""Each eye measured, judged and reported on its own.

The bug this closes: the first live frame reported a pooled iris over 292
pixels, and both sides as "not measured" with no reason at all. Neither half of
that was a lie on its own - the sides really had no sample, because there was
nowhere in ``samples`` to put one, and they really had no reason, because on
geometry they had both passed. Together they were unreadable.

Underneath it was the older mistake the sclera had already been through: the
pooled iris was measured in one go over the union of the two masks, so a side
that would have failed its own checks was measured into ``samples.iris`` anyway.
"""

from __future__ import annotations

import numpy as np

from color_engine.config import EngineConfig
from color_engine.sampling import sample_zones, zone_statuses
from color_engine.zones import build_zones

from .test_sclera_pooling import _face
from .test_zones import _synthetic_detection


def test_both_eyes_reach_the_contract_when_both_are_usable(config: EngineConfig) -> None:
    detection = _synthetic_detection()
    zones = build_zones(detection, config)

    measured = sample_zones(_face(detection), zones, config)
    statuses = zone_statuses(zones, measured)

    assert statuses["iris_left"].usable and statuses["iris_left"].reason is None
    assert statuses["iris_right"].usable and statuses["iris_right"].reason is None
    assert measured.samples.iris_parts.left is not None
    assert measured.samples.iris_parts.right is not None
    assert measured.samples.iris is not None


def test_a_usable_side_is_never_reported_without_its_numbers(config: EngineConfig) -> None:
    # This is the whole bug. "usable, but not measured, and no reason" is not an
    # answer anybody can act on.
    #
    # One direction only. The converse - numbers therefore usable - was asserted
    # here once and is no longer true: a rejected side keeps its numbers, which
    # is the only way to see by how much a rule missed.
    detection = _synthetic_detection()
    zones = build_zones(detection, config)

    measured = sample_zones(_face(detection), zones, config)
    statuses = zone_statuses(zones, measured)

    for side in ("left", "right"):
        if statuses[f"iris_{side}"].usable:
            assert getattr(measured.samples.iris_parts, side) is not None, side


def test_every_side_without_a_sample_carries_a_reason(config: EngineConfig) -> None:
    detection = _synthetic_detection()
    zones = build_zones(detection, config)
    measured = sample_zones(_face(detection), zones, config)
    statuses = zone_statuses(zones, measured)

    for name in ("iris_left", "iris_right", "sclera_left", "sclera_right"):
        parts = (
            measured.samples.iris_parts
            if name.startswith("iris")
            else (measured.samples.sclera_parts)
        )
        if getattr(parts, name.rsplit("_", 1)[1]) is None:
            assert statuses[name].reason, f"{name} has no sample and no reason"


def test_an_unusable_side_says_why_and_stays_out_of_the_pool(config: EngineConfig) -> None:
    # Blow one eye. It must be reported with a reason, keep the numbers that
    # earned it, and not be in the pool - the pooled iris must be the other eye
    # alone rather than the two mixed.
    detection = _synthetic_detection()
    zones = build_zones(detection, config)
    clean = _face(detection)

    blown = clean.copy()
    right = zones.get("iris_right")
    assert right is not None
    blown[right.mask > 0] = 255

    measured = sample_zones(blown, zones, config)
    statuses = zone_statuses(zones, measured)

    assert not statuses["iris_right"].usable
    assert statuses["iris_right"].reason == "overexposed"
    # Kept, and saying what it was: a patch of ceiling reads light and flat.
    right_sample = measured.samples.iris_parts.right
    assert right_sample is not None
    assert right_sample.L > 95.0
    assert measured.samples.iris_parts.left is not None


def test_the_pooled_iris_is_only_the_eyes_that_passed(config: EngineConfig) -> None:
    detection = _synthetic_detection()
    zones = build_zones(detection, config)
    clean = _face(detection)

    blown = clean.copy()
    right = zones.get("iris_right")
    assert right is not None
    blown[right.mask > 0] = 255

    measured = sample_zones(blown, zones, config)

    assert measured.samples.iris is not None
    assert measured.samples.iris_parts.left is not None
    # Pooled from one eye's kept pixels, so it IS that eye - not an average of
    # a real iris and a patch of ceiling.
    assert measured.samples.iris.L == measured.samples.iris_parts.left.L


def test_the_pool_is_measured_over_kept_pixels_not_the_union_of_the_masks(
    config: EngineConfig,
) -> None:
    # Measuring the union again would re-admit everything each eye's own
    # catchlight filter and percentile trim threw out.
    detection = _synthetic_detection()
    zones = build_zones(detection, config)
    measured = sample_zones(_face(detection), zones, config, keep_masks=True)

    pooled = measured.sampled_masks.get("iris")
    assert pooled is not None
    per_side = [measured.sampled_masks[f"iris_{s}"] for s in ("left", "right")]
    assert int(np.count_nonzero(pooled)) == int(np.count_nonzero(np.maximum.reduce(per_side)))


def test_the_sclera_sides_reach_the_contract_the_same_way(config: EngineConfig) -> None:
    detection = _synthetic_detection()
    zones = build_zones(detection, config)

    measured = sample_zones(_face(detection), zones, config)
    statuses = zone_statuses(zones, measured)

    for side in ("left", "right"):
        if statuses[f"sclera_{side}"].usable:
            assert getattr(measured.samples.sclera_parts, side) is not None, side


def test_a_rejected_sclera_keeps_the_numbers_it_was_rejected_on(
    config: EngineConfig,
) -> None:
    # The synthetic right eye is painted a colour no sclera is, and is refused
    # for carrying as much chroma as the skin. Refused on a NUMBER - so the
    # number is what the rejection has to be reviewed on, and it is reported.
    detection = _synthetic_detection()
    zones = build_zones(detection, config)

    measured = sample_zones(_face(detection), zones, config)
    statuses = zone_statuses(zones, measured)

    assert statuses["sclera_right"].reason == "more_chromatic_than_skin"
    rejected = measured.samples.sclera_parts.right
    assert rejected is not None
    # Via a local: ruff reads `.C` as a constant and calls the comparison a
    # Yoda condition whichever way round it is written.
    chroma = rejected.C
    assert chroma > config.sclera_chroma_floor
    # And it is not in the pool: that is the left eye, untouched.
    left = measured.samples.sclera_parts.left
    assert left is not None and measured.samples.sclera is not None
    assert measured.samples.sclera.L == left.L
