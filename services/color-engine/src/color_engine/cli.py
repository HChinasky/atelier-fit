"""Command line interface.

    color-engine analyze photo.jpg [--quiz quiz.json] [--json]
    color-engine report ./photos --out reports/2026-09-16T12-00-00/
    color-engine palettes draft --out database/seeders/data/season_palettes.json

``report`` is the only thing in this package that writes an image to disk. It
embeds the photos it was given into a single HTML file, so treat its output as
personal data: ``reports/`` is gitignored, and it should not outlive the review
it was made for.
"""

from __future__ import annotations

import json
import logging
import sys
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path

import typer

from .config import EngineConfig
from .debug import LandmarkLabels
from .engine import Engine, __version__
from .fixtures import (
    FILENAME_CONVENTION,
    LABELS_FILENAME,
    LabelsError,
    Resolution,
    resolve_labels,
)
from .io import SUPPORTED_SUFFIXES, is_supported_suffix
from .models import AnalysisResult, Quiz
from .palettes import draft_palettes
from .report import build_entry, build_report, build_stability, summarise

app = typer.Typer(
    add_completion=False,
    help="Seasonal colour analysis from a face photo.",
    no_args_is_help=True,
)

palettes = typer.Typer(
    add_completion=False,
    help="Season palettes. Draft only - see `palettes draft --help`.",
    no_args_is_help=True,
)
app.add_typer(palettes, name="palettes")

# The extensions ``report`` will open, derived from the same format table
# ``io.load_image`` decides by, so the two cannot drift apart.
IMAGE_SUFFIXES = SUPPORTED_SUFFIXES


@palettes.command("draft")
def palettes_draft(
    out: Path = typer.Option(..., "--out", help="Where to write the JSON."),
) -> None:
    """Generate the draft season palettes and write them to a file.

    NOBODY CHOSE THESE COLOURS. They are constructed from the season prototypes
    in LCh - see the module docstring in ``palettes.py`` - so that the page has
    something real to render and a stylist has something concrete to disagree
    with. The file carries ``draft: true`` and the consumer must say so.

    Deterministic: the same input always writes the same bytes, because the
    output is committed and seeded, and a diff nobody can review is not a diff.
    """
    document = draft_palettes()
    out.parent.mkdir(parents=True, exist_ok=True)

    # LF and a trailing newline whatever the platform: this file is committed,
    # and a regeneration on Windows must not show up as 300 changed lines.
    out.write_text(
        json.dumps(document, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
        newline="\n",
    )

    colours = sum(
        len(group["colors"]) for season in document["seasons"] for group in season["groups"]
    )
    typer.echo(
        f"{len(document['seasons'])} seasons, {colours} colours "
        f"(generator {document['generator_version']}, draft) -> {out}"
    )


@dataclass(frozen=True)
class Selection:
    """Which files in a directory ``report`` will open, and which it will not.

    Skipped files are carried rather than dropped. A reference set with one
    ``.HEIC`` quietly missing from the report is a wrong conclusion about the
    set, not a tidy run.
    """

    photos: list[Path]
    skipped: list[tuple[Path, str]]


def select_photos(directory: Path) -> Selection:
    """Split a directory into the images we can decode and everything else."""
    photos: list[Path] = []
    skipped: list[tuple[Path, str]] = []
    for path in sorted(directory.iterdir()):
        if not path.is_file() or path.name == LABELS_FILENAME:
            continue
        if is_supported_suffix(path):
            photos.append(path)
        else:
            skipped.append((path, f"unsupported extension {path.suffix.lower() or '(none)'}"))
    return Selection(photos=photos, skipped=skipped)


def analysable(photos: list[Path], resolution: Resolution | None) -> Selection:
    """Drop the photos ``--by-person`` could not place.

    Skipped has to mean *not analysed*. A file that is in neither
    ``labels.json`` nor the filename convention has no person and no lighting,
    so it cannot appear in any drift number - and analysing it anyway put a face
    in the HTML report that no column of the summary accounted for, which is a
    worse kind of wrong than leaving it out.
    """
    if resolution is None:
        return Selection(photos=photos, skipped=[])
    unplaced = dict(resolution.skipped)
    return Selection(
        photos=[photo for photo in photos if photo.name in resolution.labels],
        skipped=[(photo, unplaced[photo.name]) for photo in photos if photo.name in unplaced],
    )


def report_config(by_person: bool) -> EngineConfig:
    """The engine configuration ``report`` runs with.

    A plain ``report`` is a window onto the engine: it runs the configuration it
    was given, so what the HTML shows is what a caller would actually get back.
    Switching white balance on behind their back would make the report a
    picture of a different engine.

    ``--by-person`` is the exception, and only because it is an instrument
    rather than a window. It exists to measure what the correction does to a
    set, and a before/after with nothing in the "after" column measures nothing.
    The HTML says so where it shows those numbers.
    """
    config = EngineConfig()
    if not by_person:
        return config
    return config.model_copy(update={"white_balance_enabled": True})


@app.command()
def analyze(
    path: Path = typer.Argument(..., exists=True, dir_okay=False, readable=True),
    quiz: Path | None = typer.Option(
        None, "--quiz", exists=True, dir_okay=False, help="Quiz answers as JSON."
    ),
    compact: bool = typer.Option(
        False, "--json", help="Print one compact JSON line instead of indented JSON."
    ),
    verbose: bool = typer.Option(False, "--verbose", "-v", help="Log step timings."),
) -> None:
    """Analyse a single photo and print the result as JSON."""
    _configure_logging(verbose)
    answers = _load_quiz(quiz)

    with Engine(EngineConfig()) as engine:
        result = engine.analyze(path.read_bytes(), quiz=answers)

    typer.echo(result.to_json(indent=None if compact else 2))
    if result.rejected:
        raise typer.Exit(code=2)


@app.command()
def report(
    directory: Path = typer.Argument(..., exists=True, file_okay=False, readable=True),
    out: Path | None = typer.Option(
        None, "--out", help="Output directory. Defaults to reports/<timestamp>/."
    ),
    quiz: Path | None = typer.Option(
        None, "--quiz", exists=True, dir_okay=False, help="Quiz answers, applied to every photo."
    ),
    landmark_labels: LandmarkLabels = typer.Option(
        LandmarkLabels.NONE.value,
        "--landmark-labels",
        help="Print landmark indices on the overlay: 'zones' for the points the zones use, "
        "'all' for every one of the 478. This is how you correct a polygon.",
    ),
    by_person: bool = typer.Option(
        False,
        "--by-person",
        help="Group by person_id and measure how far each lighting drifts from that person's "
        "daylight shot. Reads labels.json when there is one and falls back to the "
        "<person_id>_<lighting>.<ext> filename convention. Also writes summary.json.",
    ),
    verbose: bool = typer.Option(False, "--verbose", "-v"),
) -> None:
    """Analyse every photo in a directory into one self-contained HTML report."""
    _configure_logging(verbose)
    answers = _load_quiz(quiz)

    selection = select_photos(directory)
    photos = selection.photos
    skipped = list(selection.skipped)
    if not photos:
        _echo_skipped(skipped)
        typer.echo(f"No images found in {directory}", err=True)
        raise typer.Exit(code=1)

    resolution: Resolution | None = None
    if by_person:
        try:
            resolution = resolve_labels(directory, [photo.name for photo in photos])
        except LabelsError as exc:
            typer.echo(str(exc), err=True)
            raise typer.Exit(code=1) from exc
        grouped = analysable(photos, resolution)
        photos = grouped.photos
        skipped += grouped.skipped
        if not photos:
            _echo_skipped(skipped)
            typer.echo(
                f"Nothing to group by: no {LABELS_FILENAME} and no filename matches "
                f"{FILENAME_CONVENTION}.",
                err=True,
            )
            raise typer.Exit(code=1)

    stamp = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H-%M-%S")
    out_dir = out or Path("reports") / stamp

    config = report_config(by_person)

    entries = []
    results: dict[str, AnalysisResult] = {}
    with Engine(config) as engine:
        for photo in photos:
            data = photo.read_bytes()
            typer.echo(f"  {photo.name}", err=True)
            result = engine.analyze(data, quiz=answers, debug=True)
            results[photo.name] = result
            entries.append(build_entry(photo.name, data, result, landmark_labels))

    people = build_stability(resolution.labels, results) if resolution is not None else None
    summary = (
        summarise(people, __version__, labels=resolution)
        if people is not None and resolution is not None
        else None
    )

    destination = build_report(
        entries, out_dir, title=f"color-engine report · {stamp}", people=people, summary=summary
    )
    typer.echo(f"\n{len(entries)} photo(s) -> {destination}", err=True)
    if summary is not None:
        typer.echo(f"stability summary -> {out_dir / 'summary.json'}", err=True)
    if resolution is not None:
        for warning in resolution.warnings:
            typer.echo(f"warning: {warning}", err=True)
    _echo_skipped(skipped)
    typer.echo(
        "This file embeds the photos. Keep it out of shared storage and delete it "
        "when the review is done.",
        err=True,
    )


def _echo_skipped(skipped: list[tuple[Path, str]]) -> None:
    """Name every file the run did not open, and why.

    Printed on the way out of an empty run too. A directory where nothing was
    analysed still owes the person the same list the HTML would have carried -
    "No images found" on its own leaves them guessing which of their files the
    tool disagreed with.
    """
    if not skipped:
        return
    typer.echo(f"\nSkipped {len(skipped)} file(s):", err=True)
    for path, reason in skipped:
        typer.echo(f"  {path.name} - {reason}", err=True)


def _load_quiz(path: Path | None) -> Quiz | None:
    if path is None:
        return None
    return Quiz.model_validate_json(path.read_text(encoding="utf-8"))


def _configure_logging(verbose: bool) -> None:
    logging.basicConfig(
        stream=sys.stderr,
        level=logging.INFO if verbose else logging.WARNING,
        format="%(levelname)s %(name)s %(message)s",
    )


def main() -> None:
    app()


if __name__ == "__main__":  # pragma: no cover
    main()
