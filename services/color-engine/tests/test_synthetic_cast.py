"""Casting a known light over known colours, and asking for them back.

Two levels, and they prove different things.

**On samples** (always runs). Take a plausible skin and sclera in CIELAB, adapt
them to an illuminant, hand the result to the estimator, and check the skin
comes back where it started. This is a closed loop - the cast is applied with
the same transform the estimator inverts - so it proves the *algebra*: that the
von Kries reasoning, the CAT16 matrices and the target chromaticity compose into
an identity, and that a light too far off is refused rather than corrected.

**On images** (needs ``COLOR_ENGINE_FIXTURES_DIR``). Take the set's real daylight
frames, apply a cast in linear RGB, run the whole pipeline, and compare the
skin's distance from the original before and after correction. This exercises
detection, zones, sclera selection and the scene fallback together, on real
pixels.

Neither is a claim about real light. A synthetic cast laid over an already
processed JPEG is not what a bulb does to a sensor: the camera's own auto white
balance has already moved the image, the tone curve has already clipped
something, and a real room mixes two illuminants and bounces both. Passing these
says the code does what it says; it does not say the estimate survives a real
photograph. That is what the reference set and the stability report are for.
"""

from __future__ import annotations

import io

import colour
import numpy as np
import pytest
from PIL import Image

from color_engine.arrays import FloatArray, ImageArray
from color_engine.config import EngineConfig
from color_engine.engine import Engine
from color_engine.fixtures import Lighting, fixtures_dir, resolve_labels
from color_engine.io import is_supported_suffix
from color_engine.models import AnalysisResult, LabSample, RejectReason, WhiteBalanceMethod
from color_engine.white_balance import D65_XYZ, SRGB_TO_XYZ, adapt_sample, illuminant_from_sclera

ILLUMINANTS = colour.CCS_ILLUMINANTS["CIE 1931 2 Degree Standard Observer"]

# The four the brief asks for, plus D75 for the cool side and a coloured wash
# that is meant to be refused rather than corrected.
CAST_ILLUMINANTS = ("A", "FL2", "FL11", "D50", "D75")
COLOURED_WASH = (0.55, 0.40)

TRUE_SKIN = (68.0, 16.0, 19.0)
TRUE_SCLERA = (87.0, 0.0, 0.0)


def _white(xy: object) -> FloatArray:
    xyz = np.asarray(colour.xy_to_XYZ(np.asarray(xy, dtype=np.float64)), dtype=np.float64)
    return np.asarray(xyz / xyz[1], dtype=np.float64)


def _lit_by(lab: tuple[float, float, float], white: FloatArray) -> tuple[float, float, float]:
    """The inverse of the correction: what this colour measures as under ``white``."""
    under = colour.adaptation.chromatic_adaptation_VonKries(
        colour.Lab_to_XYZ(np.asarray(lab, dtype=np.float64)), D65_XYZ, white, transform="CAT16"
    )
    values = colour.XYZ_to_Lab(under)
    return (float(values[0]), float(values[1]), float(values[2]))


def _sample(lab: tuple[float, float, float]) -> LabSample:
    lightness, a, b = lab
    return LabSample(
        L=round(lightness, 3),
        a=round(a, 3),
        b=round(b, 3),
        C=round(float(np.hypot(a, b)), 3),
        h=round(float(np.degrees(np.arctan2(b, a)) % 360.0), 3),
        pixels=800,
        L_iqr=3.0,
    )


def _delta_e(lab: tuple[float, float, float], other: tuple[float, float, float]) -> float:
    return float(
        colour.delta_E(
            np.asarray(lab, dtype=np.float64),
            np.asarray(other, dtype=np.float64),
            method="CIE 2000",
        )
    )


def _lab_of(sample: LabSample) -> tuple[float, float, float]:
    return (sample.L, sample.a, sample.b)


# --- on samples -------------------------------------------------------------


@pytest.mark.parametrize("name", CAST_ILLUMINANTS)
def test_a_known_cast_is_undone(name: str, config: EngineConfig) -> None:
    white = _white(ILLUMINANTS[name])
    measured_skin = _lit_by(TRUE_SKIN, white)
    measured_sclera = _lit_by(TRUE_SCLERA, white)

    estimated = illuminant_from_sclera(_sample(measured_sclera), config)
    corrected = adapt_sample(_sample(measured_skin), estimated)

    before = _delta_e(measured_skin, TRUE_SKIN)
    after = _delta_e(_lab_of(corrected), TRUE_SKIN)
    assert before > 2.0, f"{name} is not a cast worth correcting"
    assert after < 0.5, f"{name}: {before:.2f} -> {after:.2f}"


def test_a_coloured_wash_is_refused_not_corrected(config: EngineConfig) -> None:
    """There is nothing to bring back: a stage light or a sodium lamp leaves
    whole regions of the spectrum out of the frame."""
    from color_engine.models import Samples, SkinParts
    from color_engine.sampling import ScleraQuality, ScleraReason, ScleraSide
    from color_engine.white_balance import apply_white_balance
    from color_engine.zones import Zones

    white = _white(COLOURED_WASH)
    sclera = _sample(_lit_by(TRUE_SCLERA, white))
    skin = _sample(_lit_by(TRUE_SKIN, white))
    side = ScleraSide(
        side="left",
        sample=sclera,
        quality=ScleraQuality(400, 200, 0, sclera.L, sclera.C, 2.0, 2.0, ScleraReason.OK),
    )

    result = apply_white_balance(
        np.zeros((8, 8, 3), dtype=np.uint8),
        Zones(),
        Samples(skin=skin, skin_parts=SkinParts(left_cheek=skin), sclera=sclera),
        (side,),
        config,
    )

    assert result.reject_reason is RejectReason.COLOR_CAST
    assert result.info.correction_strength > config.max_correction_duv


# --- on images --------------------------------------------------------------


def cast_image(data: bytes, white: FloatArray) -> bytes:
    """Re-light an encoded photo, in linear RGB, and re-encode it losslessly.

    Linear, because that is the only place a change of illuminant is a
    multiplication. PNG out, because a second round of JPEG would add its own
    colour error to the one being measured.
    """
    with Image.open(io.BytesIO(data)) as img:
        rgb: ImageArray = np.asarray(img.convert("RGB"), dtype=np.uint8)

    linear = np.asarray(colour.cctf_decoding(rgb / 255.0, function="sRGB"), dtype=np.float64)
    under = colour.adaptation.chromatic_adaptation_VonKries(
        linear @ SRGB_TO_XYZ.T, D65_XYZ, white, transform="CAT16"
    )
    out = np.clip(colour.XYZ_to_sRGB(under), 0.0, 1.0) * 255.0

    buffer = io.BytesIO()
    Image.fromarray(out.astype(np.uint8)).save(buffer, format="PNG")
    return buffer.getvalue()


def _skin_distance(result: AnalysisResult, reference: LabSample) -> float | None:
    if result.samples.skin is None:
        return None
    return _delta_e(_lab_of(result.samples.skin), _lab_of(reference))


@pytest.mark.requires_fixtures
@pytest.mark.requires_models
def test_a_cast_over_real_frames_is_reduced(engine: Engine) -> None:
    """The daylight frames of the reference set, re-lit by a tungsten bulb.

    No per-photo threshold: the point is the direction, not a number. A
    correction that left the frames further from where they started than the
    cast put them would mean the estimate is wrong, and that is what this
    catches. The size of the improvement is the stability report's business.
    """
    directory = fixtures_dir()
    if directory is None:
        pytest.skip("COLOR_ENGINE_FIXTURES_DIR is not set")

    names = [p.name for p in sorted(directory.iterdir()) if p.is_file() and is_supported_suffix(p)]
    labels = resolve_labels(directory, names).labels
    daylight = [n for n, label in labels.items() if label.lighting is Lighting.DAYLIGHT]
    if not daylight:
        pytest.skip("the reference set has no daylight frames to re-light")

    white = _white(ILLUMINANTS["A"])
    before: list[float] = []
    after: list[float] = []
    for name in daylight:
        data = (directory / name).read_bytes()
        original = engine.analyze(data)
        if original.rejected or original.samples.skin is None:
            continue

        cast = engine.analyze(cast_image(data, white), debug=True)
        uncorrected = getattr(cast.debug, "uncorrected_samples", None)
        if uncorrected is None or uncorrected.skin is None:
            continue

        raw = _delta_e(_lab_of(uncorrected.skin), _lab_of(original.samples.skin))
        corrected = _skin_distance(cast, original.samples.skin)
        if corrected is None:
            continue
        before.append(raw)
        after.append(corrected)
        # The numbers are the point of this test; pytest shows them with -s.
        print(
            f"{name}: skin dE {raw:6.2f} -> {corrected:6.2f} via {cast.white_balance.method.value}"
        )

    if not before:
        pytest.skip("no daylight frame survived the pipeline on both passes")
    assert float(np.median(after)) < float(np.median(before)), (
        f"correction made it worse: median {np.median(before):.2f} -> {np.median(after):.2f}"
    )


@pytest.mark.requires_models
def test_the_cast_helper_really_changes_the_colour() -> None:
    """A guard on the guard: if ``cast_image`` were a no-op the test above would
    pass for the wrong reason."""
    flat = Image.new("RGB", (64, 64), (180, 170, 160))
    buffer = io.BytesIO()
    flat.save(buffer, format="PNG")

    with Image.open(io.BytesIO(cast_image(buffer.getvalue(), _white(ILLUMINANTS["A"])))) as out:
        warmed = np.asarray(out.convert("RGB"), dtype=np.int16)[32, 32]

    assert warmed[0] > 180, "a tungsten bulb should push red up"
    assert warmed[2] < 160, "...and blue down"


def test_the_engine_reports_which_method_it_used() -> None:
    """A smoke check on the contract field the tests above read."""
    assert {m.value for m in WhiteBalanceMethod} == {"sclera", "scene", "none"}
