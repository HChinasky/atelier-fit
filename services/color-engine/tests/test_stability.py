"""The by-person stability report.

Built from synthetic AnalysisResults, so it runs without models or photos. The
one test that needs the real reference set is marked and skips without it.
"""

from __future__ import annotations

import json
from pathlib import Path

import pytest

from color_engine.fixtures import Label, Resolution, fixtures_dir, load_labels
from color_engine.models import (
    AnalysisResult,
    LabSample,
    RejectReason,
    Samples,
    Status,
    WhiteBalance,
    WhiteBalanceMethod,
)
from color_engine.report import build_report, build_stability, delta_e, summarise
from color_engine.report.builder import ReportEntry


def _sample(lightness: float, a: float, b: float) -> LabSample:
    return LabSample(L=lightness, a=a, b=b, C=8.0, h=40.0, pixels=1000, L_iqr=4.0)


def _ok(
    skin: LabSample,
    hair: LabSample | None = None,
    method: WhiteBalanceMethod = WhiteBalanceMethod.NONE,
) -> AnalysisResult:
    return AnalysisResult(
        status=Status.OK,
        samples=Samples(skin=skin, hair=hair),
        white_balance=WhiteBalance(method=method),
        algo_version="0.1.0",
    )


class _Debug:
    """The one debug artifact the stability report reads: what was measured
    before the illuminant was divided out."""

    def __init__(self, uncorrected: Samples) -> None:
        self.uncorrected_samples = uncorrected


def _rejected(reason: RejectReason = RejectReason.NO_FACE) -> AnalysisResult:
    return AnalysisResult(status=Status.REJECTED, reject_reason=reason, algo_version="0.1.0")


def _label(person: str, lighting: str | None) -> Label:
    return Label.model_validate({"person_id": person, "lighting": lighting})


DAYLIGHT_SKIN = _sample(70.0, 12.0, 14.0)
WARM_SKIN = _sample(68.0, 16.0, 22.0)  # a tungsten bulb: warmer and a touch darker


def test_delta_e_is_zero_for_identical_samples() -> None:
    assert delta_e(DAYLIGHT_SKIN, DAYLIGHT_SKIN) == 0.0


def test_delta_e_is_none_when_a_sample_is_missing() -> None:
    assert delta_e(DAYLIGHT_SKIN, None) is None
    assert delta_e(None, None) is None


def test_daylight_is_the_reference_and_measures_zero() -> None:
    labels = {"a.jpg": _label("p1", "daylight"), "b.jpg": _label("p1", "indoor_warm")}
    results = {"a.jpg": _ok(DAYLIGHT_SKIN), "b.jpg": _ok(WARM_SKIN)}

    people = build_stability(labels, results)

    assert len(people) == 1
    person = people[0]
    assert person.reference_file == "a.jpg"
    assert person.reference_kind == "daylight"
    by_file = {p.filename: p for p in person.photos}
    assert by_file["a.jpg"].is_reference is True
    assert by_file["a.jpg"].deltas["skin"] == 0.0
    assert by_file["b.jpg"].deltas["skin"] is not None
    assert by_file["b.jpg"].deltas["skin"] > 1.0


def test_without_a_daylight_shot_the_baseline_is_the_persons_median() -> None:
    labels = {"a.jpg": _label("p1", "indoor_warm"), "b.jpg": _label("p1", "flash")}
    results = {"a.jpg": _ok(DAYLIGHT_SKIN), "b.jpg": _ok(WARM_SKIN)}

    person = build_stability(labels, results)[0]

    assert person.reference_file is None
    assert person.reference_kind == "median"
    assert all(p.deltas["skin"] is not None for p in person.photos)


def test_a_rejected_photo_does_not_become_the_reference() -> None:
    labels = {"a.jpg": _label("p1", "daylight"), "b.jpg": _label("p1", "flash")}
    results = {"a.jpg": _rejected(), "b.jpg": _ok(WARM_SKIN)}

    person = build_stability(labels, results)[0]

    assert person.reference_file is None
    by_file = {p.filename: p for p in person.photos}
    assert by_file["a.jpg"].status == "rejected"
    assert by_file["a.jpg"].reject_reason == "no_face"
    assert by_file["a.jpg"].deltas["skin"] is None


def test_people_are_kept_apart() -> None:
    labels = {
        "p1_a.jpg": _label("p1", "daylight"),
        "p2_a.jpg": _label("p2", "daylight"),
        "p2_b.jpg": _label("p2", "flash"),
    }
    results = {name: _ok(DAYLIGHT_SKIN) for name in labels}

    people = {p.person_id: p for p in build_stability(labels, results)}

    assert set(people) == {"p1", "p2"}
    assert len(people["p2"].photos) == 2


def test_summary_excludes_the_reference_from_the_spread() -> None:
    """The reference scores zero against itself; counting it would flatter the
    median for no reason."""
    labels = {"a.jpg": _label("p1", "daylight"), "b.jpg": _label("p1", "indoor_warm")}
    results = {"a.jpg": _ok(DAYLIGHT_SKIN), "b.jpg": _ok(WARM_SKIN)}

    summary = summarise(build_stability(labels, results), "0.1.0")

    assert summary["photos"] == 2
    assert summary["analysed"] == 2
    assert summary["skin_delta_e"]["photos"] == 1
    assert summary["skin_delta_e"]["after"]["median"] > 1.0


def test_the_drift_is_reported_before_and_after_the_correction() -> None:
    """The number stage 2 is judged by. A run that keeps no 'before' reports the
    two as equal, which is the honest answer for a run with nothing to compare."""
    labels = {"a.jpg": _label("p1", "daylight"), "b.jpg": _label("p1", "indoor_warm")}
    corrected = _ok(_sample(69.5, 12.5, 14.5))
    corrected.attach_diagnostics(_Debug(Samples(skin=WARM_SKIN)), {})
    results = {"a.jpg": _ok(DAYLIGHT_SKIN), "b.jpg": corrected}

    summary = summarise(build_stability(labels, results), "0.1.0")

    block = summary["skin_delta_e"]
    assert block["before"]["median"] > block["after"]["median"], (
        "the warm frame should end up closer to daylight than it started"
    )


def test_the_daylight_sclera_is_reported_as_the_target_to_calibrate_to() -> None:
    """The one measurement that comes back into the code as sclera_target_ab."""
    labels = {"a.jpg": _label("p1", "daylight"), "b.jpg": _label("p2", "daylight")}
    results = {}
    for name, sclera in (("a.jpg", _sample(88.0, 1.2, 4.0)), ("b.jpg", _sample(86.0, 1.8, 5.0))):
        result = _ok(DAYLIGHT_SKIN)
        result.attach_diagnostics(_Debug(Samples(skin=DAYLIGHT_SKIN, sclera=sclera)), {})
        results[name] = result

    summary = summarise(build_stability(labels, results), "0.1.0")

    target = summary["white_balance"]["sclera_daylight_ab"]
    assert target == {"photos": 2, "a": 1.5, "b": 4.5}


def test_an_indoor_sclera_is_not_mistaken_for_the_target() -> None:
    """Under a bulb the sclera measures the bulb. Only daylight frames count."""
    labels = {"a.jpg": _label("p1", "indoor_warm")}
    result = _ok(DAYLIGHT_SKIN)
    result.attach_diagnostics(_Debug(Samples(sclera=_sample(88.0, 20.0, 50.0))), {})

    summary = summarise(build_stability(labels, {"a.jpg": result}), "0.1.0")

    assert summary["white_balance"]["sclera_daylight_ab"] is None


def test_the_summary_counts_which_method_was_used() -> None:
    labels = {"a.jpg": _label("p1", "daylight"), "b.jpg": _label("p1", "flash")}
    results = {
        "a.jpg": _ok(DAYLIGHT_SKIN, method=WhiteBalanceMethod.SCLERA),
        "b.jpg": _ok(WARM_SKIN, method=WhiteBalanceMethod.SCENE),
    }

    summary = summarise(build_stability(labels, results), "0.1.0")

    assert summary["white_balance"]["methods"] == {"sclera": 1, "scene": 1}


def test_the_summary_counts_the_photos_lost_to_a_colour_cast() -> None:
    labels = {"a.jpg": _label("p1", "daylight"), "b.jpg": _label("p1", "mixed")}
    results = {
        "a.jpg": _ok(DAYLIGHT_SKIN),
        "b.jpg": _rejected(RejectReason.COLOR_CAST),
    }

    summary = summarise(build_stability(labels, results), "0.1.0")

    assert summary["white_balance"]["color_cast"] == 1


def test_summary_breaks_the_drift_down_by_lighting() -> None:
    labels = {
        "a.jpg": _label("p1", "daylight"),
        "b.jpg": _label("p1", "indoor_warm"),
        "c.jpg": _label("p1", "flash"),
    }
    results = {
        "a.jpg": _ok(DAYLIGHT_SKIN),
        "b.jpg": _ok(WARM_SKIN),
        "c.jpg": _ok(_sample(74.0, 10.0, 11.0)),
    }

    summary = summarise(build_stability(labels, results), "0.1.0")

    assert set(summary["by_lighting"]) == {"indoor_warm", "flash"}
    assert summary["by_lighting"]["indoor_warm"]["photos"] == 1
    assert summary["by_lighting"]["indoor_warm"]["after"]["median"] > 0.0


def test_an_empty_summary_still_has_its_shape() -> None:
    summary = summarise([], "0.1.0")

    assert summary["algo_version"] == "0.1.0"
    assert summary["skin_delta_e"] is None
    assert summary["white_balance"] == {
        "methods": {},
        "single_eye": 0,
        "color_cast": 0,
        "correction_strength": None,
        "sclera_daylight_ab": None,
    }
    assert summary["generated_at"]


def test_summary_says_where_the_grouping_came_from() -> None:
    """Six months on the report is the only artifact left, so it has to carry
    which photos were not in the numbers and why."""
    labels = {"p1_daylight.jpg": _label("p1", "daylight")}
    results = {"p1_daylight.jpg": _ok(DAYLIGHT_SKIN)}
    resolution = Resolution(
        labels=labels,
        warnings=["p1_daylight.jpg: labels.json says person_id='p9'"],
        skipped=[("IMG_4021.jpg", "does not match the convention")],
        source="both",
    )

    summary = summarise(build_stability(labels, results), "0.1.0", labels=resolution)

    assert summary["labels"]["source"] == "both"
    assert summary["labels"]["warnings"] == resolution.warnings
    assert summary["labels"]["skipped"] == [
        {"file": "IMG_4021.jpg", "reason": "does not match the convention"}
    ]


def test_summary_without_a_resolution_still_has_the_labels_block() -> None:
    assert summarise([], "0.1.0")["labels"] == {"source": "none", "warnings": [], "skipped": []}


def test_the_report_shows_what_was_not_grouped(tmp_path: Path) -> None:
    labels = {"p1_daylight.jpg": _label("p1", "daylight")}
    results = {"p1_daylight.jpg": _ok(DAYLIGHT_SKIN)}
    people = build_stability(labels, results)
    resolution = Resolution(
        labels=labels,
        warnings=["p1_daylight.jpg: the filename says 'p9'"],
        skipped=[("IMG_4021.jpg", "does not match the convention")],
        source="filenames",
    )

    summary = summarise(people, "0.1.0", labels=resolution)
    html = build_report([], tmp_path, "test", people=people, summary=summary).read_text(
        encoding="utf-8"
    )

    assert "IMG_4021.jpg" in html
    assert "the filename says" in html


def test_summary_is_json_serialisable(tmp_path: Path) -> None:
    labels = {"a.jpg": _label("p1", "daylight"), "b.jpg": _label("p1", "indoor_warm")}
    results = {"a.jpg": _ok(DAYLIGHT_SKIN, hair=_sample(30.0, 4.0, 6.0)), "b.jpg": _ok(WARM_SKIN)}
    summary = summarise(build_stability(labels, results), "0.1.0")

    restored = json.loads(json.dumps(summary))

    assert restored["per_person"][0]["photos"][0]["samples"]["skin"]["L"] == 70.0
    assert restored["per_person"][0]["photos"][1]["delta_e"]["skin"] is not None


def test_report_writes_summary_json_next_to_the_html(tmp_path: Path) -> None:
    labels = {"a.jpg": _label("p1", "daylight"), "b.jpg": _label("p1", "indoor_warm")}
    results = {"a.jpg": _ok(DAYLIGHT_SKIN), "b.jpg": _ok(WARM_SKIN)}
    people = build_stability(labels, results)
    summary = summarise(people, "0.1.0")

    destination = build_report([], tmp_path, "test", people=people, summary=summary)

    assert destination.is_file()
    written = json.loads((tmp_path / "summary.json").read_text(encoding="utf-8"))
    assert written == summary
    html = destination.read_text(encoding="utf-8")
    assert "Stability across lighting" in html
    assert "p1" in html


def test_a_plain_report_writes_no_summary(tmp_path: Path) -> None:
    build_report([], tmp_path, "test")

    assert not (tmp_path / "summary.json").exists()


def test_report_renders_an_entry_without_debug_artifacts(tmp_path: Path) -> None:
    """A rejected photo analysed without debug still has to render."""
    entry = ReportEntry(
        filename="a.jpg",
        status="rejected",
        reject_reason="no_face",
        source_color_space="sRGB (assumed)",
        source_format="JPEG",
        original_size="900x900",
        measured_size=None,
        downscaled=False,
        face_count=0,
        head_pose={},
        head_pose_source=None,
        timings={"load": 1.0},
        total_ms=1.0,
    )

    html = build_report([entry], tmp_path, "test").read_text(encoding="utf-8")

    assert "a.jpg" in html
    assert "no_face" in html


@pytest.mark.requires_fixtures
@pytest.mark.requires_models
def test_the_reference_set_produces_a_stability_report(tmp_path: Path) -> None:
    """Regression guard only: it checks the report builds and the numbers
    serialise. No thresholds - there is no white balance to hold them to yet."""
    directory = fixtures_dir()
    if directory is None:
        pytest.skip("COLOR_ENGINE_FIXTURES_DIR is not set")

    from color_engine import Engine, EngineConfig

    labels = load_labels(directory)
    with Engine(EngineConfig()) as engine:
        results = {name: engine.analyze((directory / name).read_bytes()) for name in labels}

    people = build_stability(labels, results)
    summary = summarise(people, "test")
    build_report([], tmp_path, "fixtures", people=people, summary=summary)

    assert len(people) == len({label.person_id for label in labels.values()})
    assert json.loads((tmp_path / "summary.json").read_text(encoding="utf-8")) == summary
