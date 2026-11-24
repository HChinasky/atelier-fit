"""Shared fixtures.

Two kinds of test live here: the ones that run anywhere (colour maths, image
decoding, the contract) and the ones that need something this repository does
not carry - the MediaPipe models, or the stylist's reference photos. The second
kind is marked and skipped rather than silently passing.
"""

from __future__ import annotations

from collections.abc import Iterator
from pathlib import Path

import pytest

from color_engine import Engine, EngineConfig


@pytest.fixture(scope="session")
def config() -> EngineConfig:
    """The shipped defaults, with white balance switched on.

    It ships off - an uncalibrated correction is worse than none for a caller
    who has not thought about it - but a suite that left it off would be testing
    the switch rather than the step. ``test_white_balance`` covers the default
    itself.
    """
    return EngineConfig(white_balance_enabled=True)


@pytest.fixture(scope="session")
def models_available(config: EngineConfig) -> bool:
    return config.face_landmarker_path().exists() and config.segmenter_path().exists()


@pytest.fixture(scope="session")
def engine(config: EngineConfig, models_available: bool) -> Iterator[Engine]:
    if not models_available:
        pytest.skip(
            f"MediaPipe models missing from {config.models_dir}. "
            "Run `python scripts/fetch_models.py`."
        )
    # One Engine for the whole session: loading the models costs far more than
    # every test that uses them put together.
    with Engine(config) as instance:
        yield instance


@pytest.fixture
def tmp_fixtures_dir(tmp_path: Path) -> Path:
    return tmp_path
