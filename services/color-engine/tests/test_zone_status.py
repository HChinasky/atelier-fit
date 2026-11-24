"""The per-zone verdict that reaches the contract.

"sclera: null" in samples is not an answer anybody can act on: a closed eye, a
mask that missed, an eye below the resolution floor and two eyes that disagreed
all look identical from there. The reason existed all along — it just lived in
the debug artifacts, where a consumer of the contract could not see it.
"""

from __future__ import annotations

import cv2

from color_engine.arrays import ImageArray
from color_engine.config import EngineConfig
from color_engine.detect import Detection
from color_engine.models import ZoneStatus
from color_engine.sampling import CONTRACT_ZONES, sample_zones, zone_statuses
from color_engine.zones import EYE_TOO_SMALL, RIGHT_IRIS, build_zones

from .test_sclera_pooling import _face
from .test_zones import _synthetic_detection


def _statuses(
    config: EngineConfig,
    detection: Detection | None = None,
    image: ImageArray | None = None,
) -> dict[str, ZoneStatus]:
    detection = detection if detection is not None else _synthetic_detection()
    image = image if image is not None else _face(detection)
    zones = build_zones(detection, config)

    return zone_statuses(zones, sample_zones(image, zones, config))


def test_every_contract_zone_gets_a_verdict(config: EngineConfig) -> None:
    statuses = _statuses(config)

    assert set(statuses) == set(CONTRACT_ZONES)
    assert all(status.usable or status.reason for status in statuses.values())


def test_a_usable_zone_carries_no_reason(config: EngineConfig) -> None:
    statuses = _statuses(config)

    assert statuses["forehead"].usable is True
    assert statuses["forehead"].reason is None


def test_a_zone_that_was_never_built_says_why(config: EngineConfig) -> None:
    """Geometry: the iris was below the resolution floor, so the eye zones were
    never built at all."""
    strict = config.model_copy(update={"min_iris_diameter_px": 60.0})

    statuses = _statuses(strict)

    for name in ("iris", "iris_left", "sclera", "sclera_right"):
        assert statuses[name].usable is False, name
        assert statuses[name].reason == EYE_TOO_SMALL, name


def test_the_sclera_reports_the_specific_reason_over_the_geometric_one(
    config: EngineConfig,
) -> None:
    """The mask was big enough — what failed was the selection inside it. Saying
    'too_few_pixels' there would send somebody looking for the wrong problem."""
    detection = _synthetic_detection()
    image = _face(detection)
    # A right eye far below the sanity floor: a mask sitting on a lash line.
    cv2.ellipse(image, (300, 335), (46, 24), 0, 0, 360, (28, 26, 24), thickness=-1)

    statuses = _statuses(config, detection, image)

    assert statuses["sclera_right"].usable is False
    assert statuses["sclera_right"].reason == "too_dark"
    assert statuses["sclera_left"].usable is True


def test_one_bad_eye_leaves_the_pooled_zone_usable(config: EngineConfig) -> None:
    """Which is the same answer the pooled sample gives, so the two agree."""
    detection = _synthetic_detection()
    image = _face(detection)
    cv2.ellipse(image, (300, 335), (46, 24), 0, 0, 360, (28, 26, 24), thickness=-1)

    zones = build_zones(detection, config)
    measured = sample_zones(image, zones, config)
    statuses = zone_statuses(zones, measured)

    assert statuses["sclera"].usable is True
    assert measured.samples.sclera is not None


def test_a_rejected_photo_reports_no_zones(config: EngineConfig) -> None:
    """Nothing was built, so there is nothing to have a verdict about. An empty
    object, not eleven rows of 'unusable' that imply a measurement was tried."""
    from color_engine.models import AnalysisResult, RejectReason, Status

    result = AnalysisResult(
        status=Status.REJECTED, reject_reason=RejectReason.NO_FACE, algo_version="0.1.0"
    )

    assert result.zones == {}


def test_the_verdicts_serialise_into_the_contract(config: EngineConfig) -> None:
    from color_engine.models import AnalysisResult, Status

    result = AnalysisResult(status=Status.OK, zones=_statuses(config), algo_version="0.1.0")

    payload = result.to_dict()["zones"]
    assert set(payload) == set(CONTRACT_ZONES)
    assert payload["forehead"] == {"usable": True, "reason": None}


def test_a_shrunken_eye_is_named_zone_by_zone(config: EngineConfig) -> None:
    """One eye below the floor, the other fine: the contract says which."""
    detection = _synthetic_detection()
    centre = detection.landmarks[RIGHT_IRIS[0]].copy()
    for offset, index in enumerate(RIGHT_IRIS[1:]):
        detection.landmarks[index] = centre + ((3.0, 0.0) if offset % 2 else (0.0, 3.0))

    statuses = _statuses(config, detection)

    assert statuses["iris_right"].reason == EYE_TOO_SMALL
    assert statuses["iris_left"].usable is True
    assert statuses["iris"].usable is True, "one good eye is still an iris"
