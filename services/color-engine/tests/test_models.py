"""The contract: what Laravel will parse must stay exactly what we promised."""

from __future__ import annotations

import json
from pathlib import Path

import jsonschema
import pytest

from color_engine.models import (
    CONTRACT_SCHEMA_PATH,
    AnalysisResult,
    Axes,
    Contrast,
    LabSample,
    Quiz,
    RejectReason,
    Samples,
    Season,
    SeasonScore,
    SkinParts,
    Status,
    WhiteBalance,
    WhiteBalanceMethod,
    contract_schema,
    write_contract_schema,
)

SEASON_SLUGS = {
    "light_spring",
    "true_spring",
    "bright_spring",
    "light_summer",
    "true_summer",
    "soft_summer",
    "soft_autumn",
    "true_autumn",
    "deep_autumn",
    "deep_winter",
    "true_winter",
    "bright_winter",
}

REJECT_REASONS = {
    "low_resolution",
    "no_face",
    "multiple_faces",
    "face_too_small",
    "face_not_frontal",
    "unsupported_format",
    "file_too_large",
    "insufficient_skin",
    "low_light",
    "uneven_light",
    "overexposed",
    "color_cast",
    "makeup_filter",
}


def _full_result() -> AnalysisResult:
    sample = LabSample(L=62.5, a=12.1, b=18.4, C=22.0, h=56.7, pixels=4210, L_iqr=3.2)
    return AnalysisResult(
        status=Status.OK,
        reject_reason=None,
        source_color_space="Display P3",
        samples=Samples(
            skin=sample,
            skin_parts=SkinParts(left_cheek=sample, right_cheek=sample, forehead=sample),
            hair=sample,
            iris=sample,
            sclera=sample,
        ),
        white_balance=WhiteBalance(
            method=WhiteBalanceMethod.SCLERA,
            single_eye=False,
            correction_strength=0.0184,
            estimated_cct=4180.0,
            target_calibrated=True,
        ),
        axes=Axes(temperature=0.3, depth=-0.2, chroma=0.1),
        contrast=Contrast(value=0.5, hair_skin=30.2, eye_skin=25.0),
        seasons=[
            SeasonScore(slug=Season.TRUE_AUTUMN, score=0.81),
            SeasonScore(slug=Season.SOFT_AUTUMN, score=0.64),
        ],
        confidence=0.72,
        algo_version="0.1.0",
    )


def test_all_twelve_seasons_are_declared() -> None:
    assert {season.value for season in Season} == SEASON_SLUGS


def test_all_reject_reasons_are_declared() -> None:
    assert {reason.value for reason in RejectReason} == REJECT_REASONS


def test_white_balance_defaults_to_having_done_nothing() -> None:
    """A result that never reached the white-balance step must not claim a
    correction it did not apply."""
    payload = json.loads(AnalysisResult(status=Status.OK, algo_version="0.1.0").to_json())

    assert payload["white_balance"] == {
        # Not enabled and no reason: a result that never reached the step has
        # nothing to say about why it did not run, and inventing one would be
        # worse than the silence.
        "enabled": False,
        "reason": None,
        "method": "none",
        "single_eye": False,
        "correction_strength": 0.0,
        "estimated_cct": None,
        "target_calibrated": False,
    }


def test_white_balance_methods_are_the_three_we_promised() -> None:
    assert {method.value for method in WhiteBalanceMethod} == {"sclera", "scene", "none"}


def test_an_uncalibrated_target_is_the_default() -> None:
    """sclera_target_ab has never been measured on a real set, so every result
    carries a systematic cool bias. A consumer has to be able to see that."""
    assert WhiteBalance().target_calibrated is False


def test_white_balance_rejects_a_negative_strength() -> None:
    """It is a distance, so it has no sign to get wrong."""
    with pytest.raises(ValueError):
        WhiteBalance(correction_strength=-0.1)


def test_json_round_trips() -> None:
    result = _full_result()

    restored = AnalysisResult.model_validate_json(result.to_json())

    assert restored == result


def test_json_validates_against_the_committed_schema() -> None:
    schema = json.loads(CONTRACT_SCHEMA_PATH.read_text(encoding="utf-8"))

    jsonschema.validate(instance=_full_result().to_dict(), schema=schema)


def test_rejected_result_validates_against_the_schema() -> None:
    schema = json.loads(CONTRACT_SCHEMA_PATH.read_text(encoding="utf-8"))
    rejected = AnalysisResult(
        status=Status.REJECTED,
        reject_reason=RejectReason.NO_FACE,
        algo_version="0.1.0",
    )

    jsonschema.validate(instance=rejected.to_dict(), schema=schema)


def test_committed_schema_is_up_to_date() -> None:
    """Regenerating must not change the file - otherwise Laravel is reading a
    schema that no longer matches what we send."""
    committed = json.loads(CONTRACT_SCHEMA_PATH.read_text(encoding="utf-8"))

    assert committed == contract_schema()


def test_write_contract_schema_writes_valid_json(tmp_path: Path) -> None:
    destination = tmp_path / "schema.json"

    write_contract_schema(destination)

    assert json.loads(destination.read_text(encoding="utf-8")) == contract_schema()


def test_diagnostics_never_reach_the_json() -> None:
    result = _full_result().attach_diagnostics({"secret": "artifact"}, {"load": 1.0})

    payload = json.loads(result.to_json())

    assert "debug" not in payload
    assert "timings_ms" not in payload
    assert result.timings_ms == {"load": 1.0}


def test_result_rejects_unknown_fields() -> None:
    with pytest.raises(ValueError):
        AnalysisResult.model_validate({"status": "ok", "algo_version": "0.1.0", "surprise": True})


def test_quiz_accepts_partial_answers() -> None:
    quiz = Quiz.model_validate({"metal": "gold"})

    assert quiz.metal is not None and quiz.metal.value == "gold"
    assert quiz.natural_hair is None


def test_quiz_rejects_unknown_answers() -> None:
    with pytest.raises(ValueError):
        Quiz.model_validate({"metal": "bronze"})
