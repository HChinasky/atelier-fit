"""A zone that was measured keeps its numbers, whatever the verdict on it.

``samples`` used to be cleared in step with ``zones``: a side that failed its
checks was reported as "not measured", which is not what happened. The cost was
not cosmetic. "darker_than_skin" does not say HOW MUCH darker, so a stored
profile could not tell a threshold that missed by 1 L* from one that missed by
30, and there is no way to review a rule nobody can see the margin on.

What the two fields mean now is split cleanly:

* ``samples``  - what was measured. Absent only when nothing was.
* ``zones``    - what may be measured WITH.

This checks the split holds for every zone the contract carries, not just for
the sclera it was first noticed on, and that nothing leaked the other way: a
rejected zone still does not reach the aggregate above it.
"""

from __future__ import annotations

import numpy as np
import pytest

from color_engine.config import EngineConfig
from color_engine.models import Samples
from color_engine.sampling import Measurements, sample_zones, zone_statuses
from color_engine.zones import Zones, build_zones

from .test_sclera_pooling import _face
from .test_zones import _synthetic_detection

# Every zone that can be blown out on this face, with where its numbers live.
# The eyes are here too: an overexposed iris used to be dropped from the
# contract exactly like a rejected sclera.
BLOWABLE: list[tuple[str, str]] = [
    ("left_cheek", "skin_parts.left_cheek"),
    ("right_cheek", "skin_parts.right_cheek"),
    ("forehead", "skin_parts.forehead"),
    ("hair", "hair"),
    ("iris_left", "iris_parts.left"),
    ("iris_right", "iris_parts.right"),
]


def _at(samples: Samples, path: str) -> object:
    value: object = samples
    for part in path.split("."):
        value = getattr(value, part)
    return value


def _blow(zones: Zones, name: str, config: EngineConfig) -> tuple[Measurements, dict]:
    """Paint one zone at the 8-bit ceiling and measure the face again."""
    detection = _synthetic_detection()
    rebuilt = build_zones(detection, config)
    image = _face(detection)
    zone = rebuilt.get(name)
    assert zone is not None, name
    image[zone.mask > 0] = 255

    measured = sample_zones(image, rebuilt, config)
    return measured, zone_statuses(rebuilt, measured)


@pytest.mark.parametrize(("name", "path"), BLOWABLE, ids=[n for n, _ in BLOWABLE])
def test_a_blown_zone_is_reported_with_the_numbers_it_was_blown_on(
    name: str, path: str, config: EngineConfig
) -> None:
    detection = _synthetic_detection()
    zones = build_zones(detection, config)
    measured, statuses = _blow(zones, name, config)

    assert statuses[name].usable is False, name
    assert statuses[name].reason == "overexposed", name

    sample = _at(measured.samples, path)
    assert sample is not None, f"{name} lost its numbers"
    # And they are the evidence for the verdict: a patch of ceiling reads light
    # and almost neutral, which is exactly what `overexposed` claims.
    assert sample.L > 95.0  # type: ignore[attr-defined]


def test_a_blown_cheek_is_reported_and_left_out_of_the_pooled_skin(
    config: EngineConfig,
) -> None:
    # Both halves of the contract in one place: the numbers are there, and the
    # aggregate above them is not computed from them.
    detection = _synthetic_detection()
    zones = build_zones(detection, config)
    measured, statuses = _blow(zones, "right_cheek", config)

    parts = measured.samples.skin_parts
    assert parts.right_cheek is not None and parts.right_cheek.L > 95.0
    assert statuses["right_cheek"].usable is False

    pooled = measured.samples.skin
    assert pooled is not None
    survivors = [p.L for p in (parts.left_cheek, parts.forehead) if p is not None]
    # Via locals: ruff reads `.L` as a constant and rewrites the comparisons
    # into Yoda form whichever way round they are written.
    pooled_lightness, blown_lightness = pooled.L, parts.right_cheek.L
    assert pooled_lightness == pytest.approx(float(np.median(survivors)))
    assert pooled_lightness < blown_lightness


def test_a_blown_iris_is_reported_and_left_out_of_the_pooled_iris(
    config: EngineConfig,
) -> None:
    detection = _synthetic_detection()
    zones = build_zones(detection, config)
    measured, statuses = _blow(zones, "iris_right", config)

    parts = measured.samples.iris_parts
    assert parts.right is not None and statuses["iris_right"].usable is False
    assert measured.samples.iris is not None and parts.left is not None
    # The pool is the surviving eye alone, not an average of it and a ceiling.
    assert measured.samples.iris.L == parts.left.L


def test_a_zone_that_was_never_measured_still_reports_nothing(config: EngineConfig) -> None:
    # The other half of the rule, and the reason "samples has numbers" cannot
    # simply be read as "usable": a zone with no pixels has nothing to keep, and
    # keeping samples must not invent one.
    detection = _synthetic_detection()
    zones = build_zones(detection, config)
    measured = sample_zones(_face(detection), zones, config)
    statuses = zone_statuses(zones, measured)

    for name in ("iris_left", "iris_right", "sclera_left", "sclera_right"):
        group = (
            measured.samples.iris_parts
            if name.startswith("iris")
            else (measured.samples.sclera_parts)
        )
        if getattr(group, name.rsplit("_", 1)[1]) is None:
            assert statuses[name].reason, name
