"""The reference photo set: labels and how they are grouped.

Real faces are never committed to this repository. The stylist's reference set
lives outside it and is pointed at with ``COLOR_ENGINE_FIXTURES_DIR``; tests that
need it carry the ``requires_fixtures`` marker and skip when it is unset.

This lives in the package rather than in ``tests/`` because ``color-engine
report --by-person`` reads the same file: grouping photos by person is a CLI
feature, not test scaffolding.

The directory holds the photos plus a ``labels.json``::

    {
      "p001_daylight.jpg": {
        "person_id": "p001",
        "lighting": "daylight",
        "season": "deep_winter",
        "season_alt": "true_winter",
        "undertone": "cool",
        "depth": "deep",
        "chroma": "bright",
        "natural_hair": true,
        "makeup": "none",
        "stylist_confidence": "high",
        "notes": ""
      }
    }

Keys are filenames relative to that directory. ``person_id`` is required and
``season`` is not: the same person is photographed under several lightings, and a
photo with no seasonal verdict is still useful for the stability report, which
only asks how far the measurements drift between conditions.

``labels.json`` is also **optional**. The two fields the stability report needs
are already in the filename if the set follows the convention
``<person_id>_<lighting>[_<n>].<ext>`` - ``p001_daylight.jpg``,
``p001_flash_2.heic`` - so a set can be shot and measured before anybody opens a
text editor. Where both exist the file wins over the name, and a disagreement is
reported rather than silently resolved: the stylist typed the label on purpose,
and a filename that contradicts it usually means a photo was renamed or copied
into the wrong person's set.

Validation collects **every** problem before raising, rather than stopping at the
first one. The person fixing this file is a stylist editing JSON by hand, and
handing them one error at a time is a bad way to spend their afternoon.
"""

from __future__ import annotations

import json
import os
import re
from collections import defaultdict
from collections.abc import Sequence
from dataclasses import dataclass, field
from enum import Enum
from pathlib import Path, PurePath

from pydantic import BaseModel, ConfigDict, ValidationError
from pydantic_core import ErrorDetails

from .models import Season

FIXTURES_ENV = "COLOR_ENGINE_FIXTURES_DIR"
LABELS_FILENAME = "labels.json"


class Lighting(str, Enum):
    """The conditions the calibration set is shot under. One person, several of
    these - which is what makes white balance measurable."""

    DAYLIGHT = "daylight"
    INDOOR_WARM = "indoor_warm"
    INDOOR_COOL = "indoor_cool"
    FLASH = "flash"
    MIXED = "mixed"


class Undertone(str, Enum):
    WARM = "warm"
    COOL = "cool"
    NEUTRAL = "neutral"


class Depth(str, Enum):
    LIGHT = "light"
    MEDIUM = "medium"
    DEEP = "deep"


class Chroma(str, Enum):
    BRIGHT = "bright"
    SOFT = "soft"


class Makeup(str, Enum):
    NONE = "none"
    LIGHT = "light"
    FULL = "full"


class StylistConfidence(str, Enum):
    HIGH = "high"
    MEDIUM = "medium"
    LOW = "low"


class LabelsError(ValueError):
    """``labels.json`` is missing, malformed, or disagrees with the directory."""

    def __init__(self, message: str, problems: list[str] | None = None) -> None:
        self.problems = problems or []
        if self.problems:
            message = message + "\n  - " + "\n  - ".join(self.problems)
        super().__init__(message)


class Label(BaseModel):
    """One photo's entry. Everything but ``person_id`` is optional."""

    model_config = ConfigDict(extra="forbid")

    person_id: str
    """Which person this is. Several photos share it, one per lighting."""

    lighting: Lighting | None = None
    season: Season | None = None
    season_alt: Season | None = None
    """The neighbouring season, when the stylist sees the person on a boundary."""

    undertone: Undertone | None = None
    depth: Depth | None = None
    chroma: Chroma | None = None
    natural_hair: bool | None = None
    makeup: Makeup | None = None
    stylist_confidence: StylistConfidence | None = None
    notes: str = ""

    @property
    def labelled_by_stylist(self) -> bool:
        return self.season is not None


def fixtures_dir() -> Path | None:
    """The reference set's directory, or None when it is not configured."""
    raw = os.environ.get(FIXTURES_ENV)
    if not raw:
        return None
    path = Path(raw)
    return path if path.is_dir() else None


def load_labels(directory: Path, *, require_files: bool = True) -> dict[str, Label]:
    """Parse and validate ``labels.json``, reporting all problems at once."""
    path = directory / LABELS_FILENAME
    if not path.is_file():
        raise LabelsError(f"{path} not found.")

    try:
        raw = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        raise LabelsError(f"{path} is not valid JSON: {exc}") from exc

    if not isinstance(raw, dict):
        raise LabelsError(f"{path} must be an object mapping filename -> label.")

    labels: dict[str, Label] = {}
    problems: list[str] = []
    for filename, entry in raw.items():
        if not isinstance(entry, dict):
            problems.append(f"{filename}: must be an object, got {type(entry).__name__}")
            continue
        try:
            labels[filename] = Label.model_validate(entry)
        except ValidationError as exc:
            problems.extend(f"{filename}: {_explain(error)}" for error in exc.errors())
            continue
        if require_files and not (directory / filename).is_file():
            problems.append(f"{filename}: labelled but not present in {directory}")

    if problems:
        raise LabelsError(f"{path} has {len(problems)} problem(s):", problems)
    return labels


FILENAME_CONVENTION = "<person_id>_<lighting>[_<n>].<ext>"
"""How a photo names itself when there is no ``labels.json`` - see the module
docstring. ``<lighting>`` is one of the :class:`Lighting` values."""

# Longest lighting value first, so ``indoor_warm`` is never mistaken for a
# person called "p001_indoor" shot under a lighting called "warm".
_FILENAME_PATTERNS: tuple[tuple[Lighting, re.Pattern[str]], ...] = tuple(
    (
        lighting,
        re.compile(rf"(?P<person_id>.+?)_{re.escape(lighting.value)}(?:_\d+)?\Z"),
    )
    for lighting in sorted(Lighting, key=lambda item: -len(item.value))
)


def parse_filename(filename: str) -> Label | None:
    """Read ``person_id`` and ``lighting`` off a filename, or return ``None``.

    Deliberately strict: a name that does not carry both is not guessed at. A
    photo the report cannot place is better named in the skipped list than
    quietly filed under a person who never posed for it.
    """
    stem = PurePath(filename).stem
    for lighting, pattern in _FILENAME_PATTERNS:
        match = pattern.fullmatch(stem)
        if match and match.group("person_id"):
            return Label(person_id=match.group("person_id"), lighting=lighting)
    return None


@dataclass(frozen=True)
class Resolution:
    """Labels for a directory of photos, however they were arrived at."""

    labels: dict[str, Label]
    warnings: list[str] = field(default_factory=list)
    """Where ``labels.json`` and the filename disagree. Not fatal - the file
    wins - but somebody should look."""

    skipped: list[tuple[str, str]] = field(default_factory=list)
    """``(filename, reason)`` for photos that could not be placed at all."""

    source: str = "none"
    """``labels.json``, ``filenames``, ``both``, or ``none``."""


def resolve_labels(directory: Path, filenames: Sequence[str]) -> Resolution:
    """Label every photo from ``labels.json``, the filename convention, or both.

    ``labels.json`` is still validated strictly when it is there - a malformed
    one raises :class:`LabelsError` rather than being shrugged off, because a
    stylist who wrote a labels file meant it.
    """
    from_file = load_labels(directory) if (directory / LABELS_FILENAME).is_file() else {}

    labels: dict[str, Label] = {}
    warnings: list[str] = []
    skipped: list[tuple[str, str]] = []
    from_name_count = 0

    for filename in filenames:
        parsed = parse_filename(filename)
        labelled = from_file.get(filename)
        if labelled is not None:
            labels[filename] = labelled
            if parsed is not None:
                warnings.extend(_disagreements(filename, labelled, parsed))
            continue
        if parsed is not None:
            labels[filename] = parsed
            from_name_count += 1
            continue
        skipped.append(
            (
                filename,
                f"not in {LABELS_FILENAME} and does not match {FILENAME_CONVENTION}",
            )
        )

    return Resolution(
        labels=labels,
        warnings=warnings,
        skipped=skipped,
        source=_source(bool(from_file), from_name_count),
    )


def _source(has_file: bool, from_name_count: int) -> str:
    if has_file and from_name_count:
        return "both"
    if has_file:
        return LABELS_FILENAME
    return "filenames" if from_name_count else "none"


def _disagreements(filename: str, labelled: Label, parsed: Label) -> list[str]:
    out: list[str] = []
    if labelled.person_id != parsed.person_id:
        out.append(
            f"{filename}: {LABELS_FILENAME} says person_id={labelled.person_id!r}, "
            f"the filename says {parsed.person_id!r} - using {labelled.person_id!r}"
        )
    if labelled.lighting is not None and labelled.lighting is not parsed.lighting:
        named = parsed.lighting.value if parsed.lighting else "-"
        out.append(
            f"{filename}: {LABELS_FILENAME} says lighting={labelled.lighting.value!r}, "
            f"the filename says {named!r} - using {labelled.lighting.value!r}"
        )
    return out


def group_by_person(labels: dict[str, Label]) -> dict[str, list[str]]:
    """Filenames per ``person_id``, each list in filename order.

    This is what the stability report iterates: one person, their photos under
    whatever lightings exist.
    """
    grouped: dict[str, list[str]] = defaultdict(list)
    for filename, label in sorted(labels.items()):
        grouped[label.person_id].append(filename)
    return dict(grouped)


def _explain(error: ErrorDetails) -> str:
    """A pydantic error as one line a non-programmer can act on."""
    location = ".".join(str(part) for part in error["loc"]) or "(entry)"
    message = str(error["msg"])
    kind = str(error["type"])
    if kind == "enum":
        return f"{location}: {message}"
    if kind == "extra_forbidden":
        return f"{location}: unknown field"
    if kind == "missing":
        return f"{location}: required"
    return f"{location}: {message}"
