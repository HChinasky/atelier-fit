"""The reference-set loader. Runs everywhere - it needs no photos, only JSON."""

from __future__ import annotations

import json
from pathlib import Path
from typing import Any

import pytest

from color_engine.fixtures import (
    LabelsError,
    Lighting,
    group_by_person,
    load_labels,
    parse_filename,
    resolve_labels,
)

FULL = {
    "person_id": "p001",
    "lighting": "daylight",
    "season": "deep_winter",
    "season_alt": "true_winter",
    "undertone": "cool",
    "depth": "deep",
    "chroma": "bright",
    "natural_hair": True,
    "makeup": "none",
    "stylist_confidence": "high",
    "notes": "studio light",
}

VALID: dict[str, Any] = {
    "p001_daylight.jpg": FULL,
    "p001_indoor_warm.jpg": {**FULL, "lighting": "indoor_warm"},
    "p002_flash.heic": {"person_id": "p002", "lighting": "flash"},
}


def _write(directory: Path, payload: object, files: tuple[str, ...] = ()) -> Path:
    (directory / "labels.json").write_text(json.dumps(payload), encoding="utf-8")
    for name in files:
        (directory / name).write_bytes(b"not really an image")
    return directory


def test_a_fully_labelled_set_loads(tmp_path: Path) -> None:
    _write(tmp_path, VALID, files=tuple(VALID))

    labels = load_labels(tmp_path)

    assert set(labels) == set(VALID)
    entry = labels["p001_daylight.jpg"]
    assert entry.person_id == "p001"
    assert entry.season is not None and entry.season.value == "deep_winter"
    assert entry.season_alt is not None and entry.season_alt.value == "true_winter"
    assert entry.undertone is not None and entry.undertone.value == "cool"
    assert entry.depth is not None and entry.depth.value == "deep"
    assert entry.chroma is not None and entry.chroma.value == "bright"
    assert entry.makeup is not None and entry.makeup.value == "none"
    assert entry.stylist_confidence is not None and entry.stylist_confidence.value == "high"
    assert entry.natural_hair is True
    assert entry.labelled_by_stylist is True


def test_a_photo_without_a_stylist_verdict_is_allowed(tmp_path: Path) -> None:
    """Unlabelled photos still drive the stability report, which only measures
    drift between lightings."""
    _write(tmp_path, VALID, files=tuple(VALID))

    labels = load_labels(tmp_path)

    assert labels["p002_flash.heic"].season is None
    assert labels["p002_flash.heic"].labelled_by_stylist is False
    assert labels["p002_flash.heic"].notes == ""


def test_person_id_is_required(tmp_path: Path) -> None:
    _write(tmp_path, {"a.jpg": {"season": "true_spring"}}, files=("a.jpg",))

    with pytest.raises(LabelsError, match="person_id"):
        load_labels(tmp_path)


def test_grouping_puts_one_persons_lightings_together(tmp_path: Path) -> None:
    _write(tmp_path, VALID, files=tuple(VALID))

    grouped = group_by_person(load_labels(tmp_path))

    assert grouped == {
        "p001": ["p001_daylight.jpg", "p001_indoor_warm.jpg"],
        "p002": ["p002_flash.heic"],
    }


def test_missing_file_is_an_error(tmp_path: Path) -> None:
    with pytest.raises(LabelsError, match="not found"):
        load_labels(tmp_path)


def test_malformed_json_is_an_error(tmp_path: Path) -> None:
    (tmp_path / "labels.json").write_text("{nope", encoding="utf-8")

    with pytest.raises(LabelsError, match="not valid JSON"):
        load_labels(tmp_path)


def test_every_problem_is_reported_at_once(tmp_path: Path) -> None:
    """A stylist editing JSON by hand should see the whole list, not the first
    line that failed."""
    _write(
        tmp_path,
        {
            "a.jpg": {"person_id": "p1", "season": "mid_autumn"},
            "b.jpg": {"person_id": "p1", "lighting": "candlelight"},
            "c.jpg": {"person_id": "p1", "eyes": "blue"},
            "d.jpg": {"person_id": "p1", "makeup": "heavy"},
        },
        files=("a.jpg", "b.jpg", "c.jpg", "d.jpg"),
    )

    with pytest.raises(LabelsError) as raised:
        load_labels(tmp_path)

    assert len(raised.value.problems) == 4
    text = str(raised.value)
    for name in ("a.jpg", "b.jpg", "c.jpg", "d.jpg"):
        assert name in text


def test_unknown_season_names_the_offending_entry(tmp_path: Path) -> None:
    _write(tmp_path, {"a.jpg": {"person_id": "p1", "season": "mid_autumn"}}, files=("a.jpg",))

    with pytest.raises(LabelsError, match=r"a\.jpg"):
        load_labels(tmp_path)


def test_unknown_key_is_rejected(tmp_path: Path) -> None:
    _write(
        tmp_path,
        {"a.jpg": {"person_id": "p1", "season": "true_summer", "eyes": "blue"}},
        files=("a.jpg",),
    )

    with pytest.raises(LabelsError, match="unknown field"):
        load_labels(tmp_path)


def test_label_without_a_photo_is_an_error(tmp_path: Path) -> None:
    _write(tmp_path, {"ghost.jpg": {"person_id": "p1", "season": "true_summer"}})

    with pytest.raises(LabelsError, match="not present"):
        load_labels(tmp_path)


def test_file_check_can_be_waived(tmp_path: Path) -> None:
    _write(tmp_path, {"ghost.jpg": {"person_id": "p1", "season": "true_summer"}})

    assert set(load_labels(tmp_path, require_files=False)) == {"ghost.jpg"}


def test_entry_that_is_not_an_object_is_reported(tmp_path: Path) -> None:
    _write(tmp_path, {"a.jpg": "deep_winter"}, files=("a.jpg",))

    with pytest.raises(LabelsError, match="must be an object"):
        load_labels(tmp_path)


def test_top_level_must_be_an_object(tmp_path: Path) -> None:
    _write(tmp_path, ["amira.jpg"])

    with pytest.raises(LabelsError, match="filename"):
        load_labels(tmp_path)


# --- the filename convention ----------------------------------------------


def test_filename_carries_person_and_lighting() -> None:
    label = parse_filename("p001_daylight.jpg")

    assert label is not None
    assert label.person_id == "p001"
    assert label.lighting is Lighting.DAYLIGHT


def test_a_repeat_shot_is_numbered_not_a_different_person() -> None:
    label = parse_filename("p001_flash_2.heic")

    assert label is not None
    assert (label.person_id, label.lighting) == ("p001", Lighting.FLASH)


def test_a_lighting_with_an_underscore_is_not_split() -> None:
    """``indoor_warm`` must not read as a person called ``p001_indoor``."""
    label = parse_filename("p001_indoor_warm.JPG")

    assert label is not None
    assert (label.person_id, label.lighting) == ("p001", Lighting.INDOOR_WARM)


def test_a_name_that_does_not_follow_the_convention_is_not_guessed_at() -> None:
    assert parse_filename("IMG_4021.jpg") is None
    assert parse_filename("daylight.jpg") is None
    assert parse_filename("_daylight.jpg") is None


def test_by_person_works_with_no_labels_file(tmp_path: Path) -> None:
    names = ("p001_daylight.jpg", "p001_flash.jpg", "p002_daylight.jpg")
    for name in names:
        (tmp_path / name).write_bytes(b"not really an image")

    resolution = resolve_labels(tmp_path, names)

    assert resolution.source == "filenames"
    assert group_by_person(resolution.labels) == {
        "p001": ["p001_daylight.jpg", "p001_flash.jpg"],
        "p002": ["p002_daylight.jpg"],
    }
    assert resolution.warnings == []
    assert resolution.skipped == []


def test_the_labels_file_outranks_the_filename(tmp_path: Path) -> None:
    _write(
        tmp_path,
        {"p001_daylight.jpg": {"person_id": "p009", "lighting": "flash"}},
        files=("p001_daylight.jpg",),
    )

    resolution = resolve_labels(tmp_path, ("p001_daylight.jpg",))

    label = resolution.labels["p001_daylight.jpg"]
    assert label.person_id == "p009"
    assert label.lighting is Lighting.FLASH
    assert len(resolution.warnings) == 2
    assert all("p001_daylight.jpg" in w for w in resolution.warnings)
    assert resolution.source == "labels.json"


def test_agreement_between_name_and_labels_is_silent(tmp_path: Path) -> None:
    _write(
        tmp_path,
        {"p001_daylight.jpg": {"person_id": "p001", "lighting": "daylight"}},
        files=("p001_daylight.jpg",),
    )

    resolution = resolve_labels(tmp_path, ("p001_daylight.jpg",))

    assert resolution.warnings == []


def test_a_labelled_photo_without_a_lighting_is_not_a_disagreement(tmp_path: Path) -> None:
    _write(tmp_path, {"p001_daylight.jpg": {"person_id": "p001"}}, files=("p001_daylight.jpg",))

    resolution = resolve_labels(tmp_path, ("p001_daylight.jpg",))

    assert resolution.warnings == []
    assert resolution.labels["p001_daylight.jpg"].lighting is None


def test_labels_file_and_filenames_can_be_mixed(tmp_path: Path) -> None:
    _write(
        tmp_path,
        {"odd-one-out.jpg": {"person_id": "p001", "lighting": "mixed"}},
        files=("odd-one-out.jpg", "p001_daylight.jpg"),
    )

    resolution = resolve_labels(tmp_path, ("odd-one-out.jpg", "p001_daylight.jpg"))

    assert resolution.source == "both"
    assert group_by_person(resolution.labels) == {"p001": ["odd-one-out.jpg", "p001_daylight.jpg"]}


def test_an_unplaceable_photo_is_skipped_with_a_reason(tmp_path: Path) -> None:
    (tmp_path / "IMG_4021.jpg").write_bytes(b"not really an image")

    resolution = resolve_labels(tmp_path, ("IMG_4021.jpg",))

    assert resolution.labels == {}
    assert len(resolution.skipped) == 1
    name, reason = resolution.skipped[0]
    assert name == "IMG_4021.jpg"
    assert "labels.json" in reason and "person_id" in reason


def test_a_broken_labels_file_still_raises(tmp_path: Path) -> None:
    """Optional means 'may be absent', not 'may be wrong'."""
    _write(tmp_path, {"a.jpg": {"season": "true_summer"}}, files=("a.jpg",))

    with pytest.raises(LabelsError, match="person_id"):
        resolve_labels(tmp_path, ("a.jpg",))
