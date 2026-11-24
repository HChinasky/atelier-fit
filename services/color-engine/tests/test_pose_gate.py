"""The frontality gate: how far a head may be turned, tilted or rolled."""

from __future__ import annotations

from color_engine.config import EngineConfig
from color_engine.detect import HeadPose, _not_frontal


def _pose(yaw=0.0, pitch=0.0, roll=0.0) -> HeadPose:
    return HeadPose(yaw_deg=yaw, pitch_deg=pitch, roll_deg=roll, asymmetry=0.0, source="matrix")


def test_an_ordinary_selfie_held_a_little_low_is_frontal():
    # 22.4 degrees of pitch with 14 of roll: refused when the limit was 22.
    assert not _not_frontal(_pose(yaw=0.6, pitch=22.4, roll=14.3), EngineConfig())


def test_pitch_is_refused_only_above_thirty_degrees():
    config = EngineConfig()

    assert not _not_frontal(_pose(pitch=29.0), config)
    assert not _not_frontal(_pose(pitch=-29.0), config)
    assert _not_frontal(_pose(pitch=31.0), config)
    assert _not_frontal(_pose(pitch=-31.0), config)


def test_yaw_and_roll_keep_their_own_limits():
    config = EngineConfig()

    assert _not_frontal(_pose(yaw=23.0), config)
    assert not _not_frontal(_pose(yaw=21.0), config)
    assert _not_frontal(_pose(roll=26.0), config)
    assert not _not_frontal(_pose(roll=24.0), config)
