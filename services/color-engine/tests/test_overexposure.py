"""Blown highlights.

The failure this exists to stop is specific and quiet. A clipped patch of skin
reads very light and almost neutral, which is exactly what genuinely cool, fair
skin reads like - so nothing downstream can tell them apart. It pulls
temperature cool, depth light and contrast wide, all at once, and every number
on the page still looks like a measurement.

The first live frame classified had one cheek at L* 91.9 / C* 2.2 beside the
other at L* 82.8 / C* 15.9. Nothing in the pipeline said a word.
"""

from __future__ import annotations

import math

import numpy as np

from color_engine.arrays import ImageArray
from color_engine.config import EngineConfig
from color_engine.detect import Detection
from color_engine.models import LabSample, RejectReason
from color_engine.quality import overexposure
from color_engine.sampling import (
    Measurements,
    clipped_fraction,
    overexposed,
    sample_zones,
    zone_statuses,
)
from color_engine.zones import build_zones

from .test_sclera_pooling import _face
from .test_zones import _synthetic_detection


def _sample(lightness: float, a: float, b: float) -> LabSample:
    return LabSample(
        L=lightness,
        a=a,
        b=b,
        C=math.hypot(a, b),
        h=math.degrees(math.atan2(b, a)) % 360.0,
        pixels=3000,
        L_iqr=3.0,
    )


def _blow(image: ImageArray, detection: Detection, zone: str, config: EngineConfig) -> ImageArray:
    """Clip one zone of a synthetic face to the ceiling."""
    built = build_zones(detection, config).get(zone)
    assert built is not None, zone
    blown = image.copy()
    blown[built.mask > 0] = 255

    return blown


# --- the measurement -------------------------------------------------------


def test_a_pixel_is_clipped_when_any_one_channel_hits_the_ceiling(
    config: EngineConfig,
) -> None:
    # Any channel, not all three: a highlight on skin clips red first and still
    # looks coloured in the other two, so waiting for white misses the damage.
    values = np.array([[255, 10, 10], [10, 10, 10], [10, 10, 10], [10, 10, 10]], dtype=np.uint8)

    assert clipped_fraction(values, config) == 0.25


def test_an_ordinary_bright_pixel_is_not_clipped(config: EngineConfig) -> None:
    values = np.full((10, 3), 240, dtype=np.uint8)

    assert clipped_fraction(values, config) == 0.0


def test_nothing_is_clipped_in_an_empty_zone(config: EngineConfig) -> None:
    assert clipped_fraction(np.zeros((0, 3), dtype=np.uint8), config) == 0.0


# --- the verdict -----------------------------------------------------------


def test_a_lot_of_clipping_condemns_a_zone(config: EngineConfig) -> None:
    sample = _sample(70.0, 12.0, 16.0)

    assert overexposed(sample, 0.5, "left_cheek", config)


def test_a_speck_of_clipping_does_not(config: EngineConfig) -> None:
    # A stray specular dot on a cheekbone is a normal photograph, and the
    # percentile trim already removes it.
    sample = _sample(70.0, 12.0, 16.0)

    assert not overexposed(sample, 0.01, "left_cheek", config)


def test_light_and_flat_together_condemn_a_zone(config: EngineConfig) -> None:
    # The live frame's right cheek, to the decimal.
    assert overexposed(_sample(91.85, 2.187, 0.357), 0.0, "right_cheek", config)


def test_fair_skin_that_still_has_its_colour_is_fine(config: EngineConfig) -> None:
    # Light ALONE is innocent. Fair skin really is light, and refusing it would
    # be the same mistake as the one this test file exists to catch, aimed the
    # other way.
    assert not overexposed(_sample(88.0, 9.0, 12.0), 0.0, "forehead", config)


def test_a_neutral_sample_that_is_not_light_is_fine(config: EngineConfig) -> None:
    # Flat ALONE is innocent too: a grey eye is a grey eye.
    assert not overexposed(_sample(45.0, 0.4, -1.1), 0.0, "iris", config)


def test_the_sclera_is_never_judged_light_and_flat(config: EngineConfig) -> None:
    # It is bright and near-neutral BY NATURE. Judging it on that pair would
    # condemn every healthy one on every well-lit photograph.
    assert not overexposed(_sample(93.0, 0.5, 2.0), 0.0, "sclera", config)


def test_a_clipped_sclera_is_still_caught(config: EngineConfig) -> None:
    # Clipping is a fact about the pixels, not about what the zone should look
    # like, so it applies everywhere.
    assert overexposed(_sample(93.0, 0.5, 2.0), 0.9, "sclera", config)


# --- the gate --------------------------------------------------------------


def _measurements(blown: set[str], skin: LabSample | None = None) -> Measurements:
    from color_engine.models import Samples, SkinParts

    return Measurements(
        samples=Samples(
            skin=skin if skin is not None else _sample(70.0, 12.0, 16.0),
            skin_parts=SkinParts(
                left_cheek=_sample(70.0, 12.0, 16.0),
                right_cheek=_sample(70.0, 12.0, 16.0),
                forehead=_sample(72.0, 11.0, 15.0),
            ),
        ),
        overexposed=frozenset(blown),
        clipped_fractions={},
    )


def test_a_clean_frame_passes_the_gate(config: EngineConfig) -> None:
    verdict = overexposure(_measurements(set()), config)

    assert verdict.reject_reason is None
    assert verdict.confidence_factor == 1.0


def test_one_blown_cheek_costs_confidence_but_is_not_a_refusal(
    config: EngineConfig,
) -> None:
    verdict = overexposure(_measurements({"right_cheek"}), config)

    assert verdict.reject_reason is None
    assert verdict.confidence_factor == config.confidence_factor_overexposed
    assert any("right_cheek" in note for note in verdict.notes)


def test_two_blown_skin_zones_are_a_refusal(config: EngineConfig) -> None:
    verdict = overexposure(_measurements({"right_cheek", "forehead"}), config)

    assert verdict.reject_reason is RejectReason.OVEREXPOSED


def test_a_blown_aggregate_is_a_refusal_even_from_one_zone(config: EngineConfig) -> None:
    # The aggregate is a median of three, so one blown cheek does not always
    # move it - and when it does, that is the case worth refusing.
    verdict = overexposure(_measurements({"skin"}), config)

    assert verdict.reject_reason is RejectReason.OVEREXPOSED


def test_the_verdict_says_which_zones_and_how_much_was_clipped(
    config: EngineConfig,
) -> None:
    verdict = overexposure(_measurements({"right_cheek"}), config)

    assert verdict.signals["overexposed_zones"] == ["right_cheek"]
    assert "clipped_fractions" in verdict.signals


# --- end to end on a synthetic face ----------------------------------------


def test_a_clipped_cheek_is_reported_unusable_with_its_reason(
    config: EngineConfig,
) -> None:
    detection = _synthetic_detection()
    image = _blow(_face(detection), detection, "right_cheek", config)
    zones = build_zones(detection, config)

    statuses = zone_statuses(zones, sample_zones(image, zones, config))

    assert not statuses["right_cheek"].usable
    assert statuses["right_cheek"].reason == "overexposed"


def test_the_skin_is_pooled_without_the_blown_cheek(config: EngineConfig) -> None:
    detection = _synthetic_detection()
    clean = _face(detection)
    image = _blow(clean, detection, "right_cheek", config)
    zones = build_zones(detection, config)

    measured = sample_zones(image, zones, config)

    assert "right_cheek" in measured.overexposed
    assert measured.samples.skin is not None
    # The blown cheek is still REPORTED - its numbers are the evidence - it just
    # does not get a vote.
    assert measured.samples.skin_parts.right_cheek is not None


def test_a_blown_forehead_does_not_condemn_the_skin_pooled_without_it(
    config: EngineConfig,
) -> None:
    """Regression: the aggregate was judged on the clipping of ALL three
    sub-zones, the excluded one included, so one fully blown forehead flagged
    ``skin`` as well and refused the photo - a phone screenshot with a bright
    forehead and two good cheeks was turned away. One blown sub-zone is meant
    to cost confidence, not the result."""
    detection = _synthetic_detection()
    image = _blow(_face(detection), detection, "forehead", config)
    zones = build_zones(detection, config)

    measured = sample_zones(image, zones, config)
    verdict = overexposure(measured, config)

    assert "forehead" in measured.overexposed
    assert "skin" not in measured.overexposed
    assert verdict.reject_reason is None
    assert verdict.confidence_factor == config.confidence_factor_overexposed


def test_an_evenly_lit_synthetic_face_is_not_condemned(config: EngineConfig) -> None:
    detection = _synthetic_detection()
    zones = build_zones(detection, config)

    measured = sample_zones(_face(detection), zones, config)

    assert measured.overexposed & {"skin", "left_cheek", "right_cheek", "forehead"} == set()


def test_two_clipped_skin_zones_refuse_the_frame(config: EngineConfig) -> None:
    detection = _synthetic_detection()
    image = _blow(_face(detection), detection, "right_cheek", config)
    image = _blow(image, detection, "left_cheek", config)
    zones = build_zones(detection, config)
    measured = sample_zones(image, zones, config)

    assert overexposure(measured, config).reject_reason is RejectReason.OVEREXPOSED


def test_contrast_leaves_a_blown_zone_out(config: EngineConfig) -> None:
    from color_engine.features import OVEREXPOSED, compute_features
    from color_engine.models import Samples, SkinParts

    samples = Samples(
        skin=_sample(88.0, 6.0, 5.0),
        skin_parts=SkinParts(),
        hair=_sample(22.0, 5.0, 22.0),
        iris=_sample(38.0, -3.0, -1.0),
    )

    features = compute_features(samples, None, config, unusable=frozenset({"skin"}))

    assert features.contrast.hair_skin is None
    reasons = {item.feature: item.reason for item in features.exclusions if item.axis == "contrast"}
    assert reasons["hair_skin"] == OVEREXPOSED


def test_a_blown_zone_is_excluded_from_the_axes_too(config: EngineConfig) -> None:
    from color_engine.features import OVEREXPOSED, compute_features
    from color_engine.models import Samples, SkinParts

    samples = Samples(
        skin=_sample(88.0, 6.0, 5.0),
        skin_parts=SkinParts(),
        hair=_sample(22.0, 5.0, 22.0),
    )

    features = compute_features(samples, None, config, unusable=frozenset({"skin"}))

    reasons = {item.feature: item.reason for item in features.exclusions if item.axis == "depth"}
    assert reasons["skin_L"] == OVEREXPOSED
