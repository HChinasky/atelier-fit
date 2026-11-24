"""End-to-end: the engine on real models.

These need the MediaPipe artifacts, so they are marked and skipped when
``models/`` is empty. Everything here uses generated images - no face photo is
committed to this repository.
"""

from __future__ import annotations

import io
from pathlib import Path

import numpy as np
import pytest
from PIL import Image

from color_engine import Engine, EngineConfig
from color_engine.errors import ModelFileNotFound
from color_engine.fixtures import fixtures_dir, load_labels
from color_engine.models import RejectReason, Status

pytestmark = pytest.mark.requires_models


def _png(img: Image.Image) -> bytes:
    buffer = io.BytesIO()
    img.save(buffer, format="PNG")
    return buffer.getvalue()


def _flat(color: tuple[int, int, int] = (120, 120, 120)) -> bytes:
    return _png(Image.new("RGB", (900, 900), color))


def _noise(seed: int = 3) -> bytes:
    rng = np.random.default_rng(seed)
    data = rng.integers(0, 256, size=(900, 900, 3), dtype=np.uint8)
    return _png(Image.fromarray(data))


def test_flat_image_is_rejected_as_no_face(engine: Engine) -> None:
    result = engine.analyze(_flat())

    assert result.status is Status.REJECTED
    assert result.reject_reason is RejectReason.NO_FACE
    assert result.samples.skin is None
    assert result.algo_version


def test_noise_is_rejected_as_no_face(engine: Engine) -> None:
    result = engine.analyze(_noise())

    assert result.status is Status.REJECTED
    assert result.reject_reason is RejectReason.NO_FACE


def test_rejection_still_reports_the_colour_space(engine: Engine) -> None:
    result = engine.analyze(_flat())

    assert result.source_color_space == "sRGB (assumed)"


def test_unsupported_input_is_rejected_not_raised(engine: Engine) -> None:
    result = engine.analyze(b"definitely not an image")

    assert result.status is Status.REJECTED
    assert result.reject_reason is RejectReason.UNSUPPORTED_FORMAT


def test_low_resolution_input_is_rejected(engine: Engine) -> None:
    result = engine.analyze(_png(Image.new("RGB", (400, 300), (120, 120, 120))))

    assert result.reject_reason is RejectReason.LOW_RESOLUTION


def test_debug_artifacts_only_appear_when_asked(engine: Engine) -> None:
    assert engine.analyze(_flat()).debug is None

    debugged = engine.analyze(_flat(), debug=True)

    assert debugged.debug is not None
    assert debugged.debug.converted_rgb is not None
    assert debugged.debug.category_mask is not None


def test_timings_are_recorded_per_step(engine: Engine) -> None:
    result = engine.analyze(_flat())

    assert set(result.timings_ms) >= {"load", "detect"}
    assert all(ms >= 0 for ms in result.timings_ms.values())


def test_engine_reports_a_missing_model_clearly() -> None:
    config = EngineConfig(models_dir=Path("definitely/not/here"))

    with pytest.raises(ModelFileNotFound) as raised:
        Engine(config)

    assert "fetch_models.py" in str(raised.value)


def test_closed_engine_refuses_work(config: EngineConfig, models_available: bool) -> None:
    if not models_available:
        pytest.skip("models not downloaded")
    engine = Engine(config)
    engine.close()

    with pytest.raises(RuntimeError):
        engine.analyze(_flat())


@pytest.mark.requires_fixtures
def test_reference_photos_are_analysed(engine: Engine) -> None:
    """Smoke test over the stylist's reference set: every labelled photo must
    reach a verdict, and every photo labelled as usable must not be rejected."""
    directory = fixtures_dir()
    if directory is None:
        pytest.skip("COLOR_ENGINE_FIXTURES_DIR is not set")

    labels = load_labels(directory)
    assert labels, "the reference set is labelled but empty"

    for filename in labels:
        result = engine.analyze((directory / filename).read_bytes())
        assert result.status in (Status.OK, Status.REJECTED)
        if result.status is Status.OK:
            assert result.samples.skin is not None


def test_a_rejection_still_describes_this_engine(
    config: EngineConfig, models_available: bool
) -> None:
    """target_calibrated is a property of the configuration, not of the
    photograph. A consumer reading it off a photo rejected before white balance
    ever ran has to get the same answer as off one that was measured."""
    if not models_available:
        pytest.skip("models not downloaded")
    calibrated = config.model_copy(update={"sclera_target_calibrated": True})

    with Engine(calibrated) as engine:
        rejected = engine.analyze(b"definitely not an image")
        no_face = engine.analyze(_flat())

    assert rejected.status is Status.REJECTED
    assert rejected.reject_reason is RejectReason.UNSUPPORTED_FORMAT
    assert rejected.white_balance.target_calibrated is True
    assert no_face.white_balance.target_calibrated is True


def test_an_uncalibrated_engine_says_so_on_a_rejection_too(engine: Engine) -> None:
    result = engine.analyze(b"definitely not an image")

    assert result.white_balance.target_calibrated is False
