"""Which files the CLI will open, and what it says about the ones it will not.

No models and no real photos: ``select_photos`` only looks at names, which is
exactly the bug this guards against - a reference set shot on an iPhone is full
of ``.HEIC`` and ``.MPO``, and silently skipping those is a wrong conclusion
about the set rather than a tidy run.
"""

from __future__ import annotations

from pathlib import Path

import pytest
from PIL import Image
from typer.testing import CliRunner

from color_engine.cli import IMAGE_SUFFIXES, analysable, app, report_config, select_photos
from color_engine.config import EngineConfig
from color_engine.fixtures import Resolution, resolve_labels
from color_engine.io import FORMAT_SUFFIXES, SUPPORTED_FORMATS


def _touch(directory: Path, *names: str) -> None:
    for name in names:
        (directory / name).write_bytes(b"not really an image")


def test_the_cli_and_the_decoder_read_the_same_table() -> None:
    every_suffix = {s for suffixes in FORMAT_SUFFIXES.values() for s in suffixes}

    assert set(IMAGE_SUFFIXES) == every_suffix
    assert set(FORMAT_SUFFIXES) == set(SUPPORTED_FORMATS)


def test_every_decodable_extension_is_accepted() -> None:
    expected = {".jpg", ".jpeg", ".png", ".webp", ".heic", ".heif", ".mpo"}

    assert set(IMAGE_SUFFIXES) == expected


def test_phone_formats_reach_the_report(tmp_path: Path) -> None:
    """The case that used to be dropped: an MPO and a HEIC, in shouting case."""
    _touch(tmp_path, "p001_daylight.MPO", "p001_flash.HEIC", "p001_indoor_warm.jpg")

    selection = select_photos(tmp_path)

    assert [p.name for p in selection.photos] == [
        "p001_daylight.MPO",
        "p001_flash.HEIC",
        "p001_indoor_warm.jpg",
    ]
    assert selection.skipped == []


def test_uppercase_extensions_are_accepted(tmp_path: Path) -> None:
    _touch(tmp_path, "A.JPG", "b.JPEG", "c.PNG", "d.WebP", "e.HEIF")

    selection = select_photos(tmp_path)

    assert len(selection.photos) == 5
    assert selection.skipped == []


def test_unsupported_files_are_listed_with_a_reason(tmp_path: Path) -> None:
    _touch(tmp_path, "a.jpg", "notes.txt", "scan.tiff", "README")

    selection = select_photos(tmp_path)

    assert [p.name for p in selection.photos] == ["a.jpg"]
    skipped = {path.name: reason for path, reason in selection.skipped}
    assert set(skipped) == {"notes.txt", "scan.tiff", "README"}
    assert skipped["scan.tiff"] == "unsupported extension .tiff"
    assert skipped["README"] == "unsupported extension (none)"


def test_labels_json_is_not_reported_as_skipped(tmp_path: Path) -> None:
    """It is the set's own metadata, not a file anybody forgot to convert."""
    _touch(tmp_path, "a.jpg", "labels.json")

    selection = select_photos(tmp_path)

    assert [p.name for p in selection.photos] == ["a.jpg"]
    assert selection.skipped == []


def test_directories_are_not_photos(tmp_path: Path) -> None:
    (tmp_path / "nested").mkdir()
    _touch(tmp_path, "a.jpg")

    selection = select_photos(tmp_path)

    assert [p.name for p in selection.photos] == ["a.jpg"]
    assert selection.skipped == []


# --- --by-person: skipped means not analysed ------------------------------


def _resolution(directory: Path, names: tuple[str, ...]) -> Resolution:
    return resolve_labels(directory, names)


def test_an_unplaceable_photo_is_not_analysed(tmp_path: Path) -> None:
    """Skipped has to mean *not analysed*. A file with no person and no lighting
    cannot appear in any drift number, so putting its face in the report is a
    row the summary does not account for."""
    _touch(tmp_path, "p001_daylight.jpg", "IMG_4021.jpg")
    photos = select_photos(tmp_path).photos

    grouped = analysable(photos, _resolution(tmp_path, ("p001_daylight.jpg", "IMG_4021.jpg")))

    assert [p.name for p in grouped.photos] == ["p001_daylight.jpg"]
    assert [p.name for p, _ in grouped.skipped] == ["IMG_4021.jpg"]
    assert "does not match" in grouped.skipped[0][1]


def test_without_by_person_nothing_is_dropped(tmp_path: Path) -> None:
    _touch(tmp_path, "IMG_4021.jpg")
    photos = select_photos(tmp_path).photos

    grouped = analysable(photos, None)

    assert grouped.photos == photos
    assert grouped.skipped == []


@pytest.mark.requires_models
def test_the_report_leaves_the_skipped_photo_out_entirely(tmp_path: Path) -> None:
    """End to end: the unplaceable file is named in the skipped list and has no
    section of its own."""
    for name in ("p001_daylight.png", "IMG_4021.png"):
        Image.new("RGB", (900, 900), (130, 120, 110)).save(tmp_path / name)
    out = tmp_path / "out"

    result = CliRunner().invoke(app, ["report", str(tmp_path), "--out", str(out), "--by-person"])

    assert result.exit_code == 0, result.output
    html = (out / "index.html").read_text(encoding="utf-8")
    assert "p001_daylight.png" in html
    assert html.count("IMG_4021.png") == 1, "only the skipped list may mention it"
    assert "not in labels.json" in html
    assert "IMG_4021.png" in result.output


def test_by_person_corrects_whatever_the_environment_says(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """--by-person is an instrument, not a window. It exists to measure what the
    correction does to a set, and a before/after with nothing in the "after"
    column measures nothing."""
    monkeypatch.setenv("COLOR_ENGINE_WHITE_BALANCE_ENABLED", "false")

    assert EngineConfig().white_balance_enabled is False, "the environment really is off"
    assert report_config(by_person=True).white_balance_enabled is True


def test_a_plain_report_shows_what_the_engine_would_return(
    monkeypatch: pytest.MonkeyPatch,
) -> None:
    """A window onto the engine. Switching white balance on behind the caller's
    back would make the report a picture of a different engine."""
    monkeypatch.setenv("COLOR_ENGINE_WHITE_BALANCE_ENABLED", "false")
    assert report_config(by_person=False).white_balance_enabled is False

    monkeypatch.setenv("COLOR_ENGINE_WHITE_BALANCE_ENABLED", "true")
    assert report_config(by_person=False).white_balance_enabled is True


@pytest.mark.requires_models
def test_the_report_says_the_after_column_is_calculated(
    tmp_path: Path, monkeypatch: pytest.MonkeyPatch
) -> None:
    """What it shows is what the correction would do, not what a deployment with
    the switch off returns."""
    monkeypatch.setenv("COLOR_ENGINE_WHITE_BALANCE_ENABLED", "false")
    Image.new("RGB", (900, 900), (130, 120, 110)).save(tmp_path / "p001_daylight.png")
    out = tmp_path / "out"

    result = CliRunner().invoke(app, ["report", str(tmp_path), "--out", str(out), "--by-person"])

    assert result.exit_code == 0, result.output
    html = (out / "index.html").read_text(encoding="utf-8")
    assert "calculated by this report" in html
    assert "COLOR_ENGINE_WHITE_BALANCE_ENABLED" in html


def test_a_directory_with_nothing_usable_still_names_every_file(tmp_path: Path) -> None:
    """Exit 1 and no HTML, but the person is owed the same list the report would
    have carried. "No images found" on its own leaves them guessing which of
    their files the tool disagreed with."""
    _touch(tmp_path, "notes.txt", "scan.tiff", "raw.dng")
    out = tmp_path / "out"

    result = CliRunner().invoke(app, ["report", str(tmp_path), "--out", str(out)])

    assert result.exit_code == 1
    assert "Skipped 3 file(s):" in result.output
    for name, reason in (
        ("notes.txt", ".txt"),
        ("scan.tiff", ".tiff"),
        ("raw.dng", ".dng"),
    ):
        assert name in result.output
        assert f"unsupported extension {reason}" in result.output
    assert "No images found" in result.output
    assert not out.exists(), "no report is written for a run with nothing in it"


def test_a_set_where_nothing_can_be_grouped_names_every_file(tmp_path: Path) -> None:
    _touch(tmp_path, "IMG_4021.jpg", "IMG_4022.jpg", "notes.txt")
    out = tmp_path / "out"

    result = CliRunner().invoke(app, ["report", str(tmp_path), "--out", str(out), "--by-person"])

    assert result.exit_code == 1
    assert "Skipped 3 file(s):" in result.output
    assert "IMG_4021.jpg" in result.output
    assert "does not match" in result.output
    assert "unsupported extension .txt" in result.output
    assert "Nothing to group by" in result.output
    assert not out.exists()
