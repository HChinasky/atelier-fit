"""Draft season palettes, generated from the prototypes.

`color-engine palettes draft --out season_palettes.json` writes one file holding
four groups per season: `neutrals`, `base` and `accents`, which are recommended,
and `avoid`, which is not.

**Nobody chose these colours.** They are constructed in LCh from the same
prototype points the classifier uses - temperature rotates the hue set, depth
moves L*, chroma moves C* - by the rules in ``data/palette.json``. Not one of
them was copied from another site's palette, and not one was picked by somebody
who does this for a living. They exist so the page has something real to render
and so a stylist has something concrete to disagree with. The output carries
``draft: true`` and every consumer must repeat it.

Once seeded, the Laravel tables are the source of truth: a row a stylist edits
is marked ``source=stylist`` and this generator never touches it again.

Determinism matters here more than it looks. The file is committed and seeded,
so a regeneration that shuffled a colour by one bit would show up as a diff
nobody could review. There is no randomness anywhere in this module, and the
gamut step uses bisection with a fixed step count rather than an iterate-until-
close loop.
"""

from __future__ import annotations

import hashlib
import json
import math
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path
from typing import Any

import colour
import numpy as np
from pydantic import BaseModel, ConfigDict, Field

from .classifier_data import ClassifierData, load_classifier_data
from .classify import AXES, prototype

PALETTE_DATA_PATH = Path(__file__).resolve().parent / "data" / "palette.json"

RECOMMENDED_GROUPS = ("neutrals", "base", "accents")
AVOID_GROUP = "avoid"

YELLOW_HUE = 90.0
"""Where the lightness-follows-hue curve peaks. sRGB's most luminous corner."""


class _Spec(BaseModel):
    """Ignores unknown keys, so the data file can carry its own reasoning."""

    model_config = ConfigDict(extra="ignore")


Arc = tuple[float, float]


class AllowedHues(_Spec):
    """The arcs one temperature stance may draw from."""

    leading: list[Arc] = Field(..., min_length=1)
    secondary: list[Arc] = Field(..., min_length=1)


class HueSpec(_Spec):
    count: int = Field(..., ge=3)
    bands: dict[str, Any] = Field(default_factory=dict)
    allowed: dict[str, AllowedHues]
    deep_lightness: float = Field(0.0, ge=0.0, le=100.0)


class LightnessSpec(_Spec):
    centre: float = Field(..., gt=0.0, lt=100.0)
    span: float = Field(..., ge=0.0)
    hue_spread: float = Field(..., ge=0.0)
    min: float = Field(..., ge=0.0, lt=100.0)
    max: float = Field(..., gt=0.0, le=100.0)


class ChromaSpec(_Spec):
    centre: float = Field(..., ge=0.0)
    span: float = Field(..., ge=0.0)


class GroupSpec(_Spec):
    size: int = Field(..., ge=5, le=8)
    chroma_ratio: float = Field(..., ge=0.0)
    lightness_spread: float = Field(..., ge=0.0)
    recommended: bool


class GamutSpec(_Spec):
    bisection_steps: int = Field(..., ge=1)
    tolerance: float = Field(..., ge=0.0)


class PaletteData(_Spec):
    generator_version: str
    draft: bool
    hues: HueSpec
    lightness: LightnessSpec
    chroma: ChromaSpec
    groups: dict[str, GroupSpec]
    gamut: GamutSpec


@lru_cache(maxsize=1)
def load_palette_data(path: Path | None = None) -> PaletteData:
    target = path or PALETTE_DATA_PATH
    return PaletteData.model_validate(json.loads(target.read_text(encoding="utf-8")))


@lru_cache(maxsize=1)
def palette_data_hash(path: Path | None = None) -> str:
    """SHA-256 of the rules, line endings normalised. See ``classifier_data``."""
    target = path or PALETTE_DATA_PATH
    return hashlib.sha256(target.read_bytes().replace(b"\r\n", b"\n")).hexdigest()


@dataclass(frozen=True)
class Swatch:
    hex: str
    lab: tuple[float, float, float]
    compressed: bool
    """True when the colour asked for more chroma than sRGB has at that hue."""

    def as_dict(self) -> dict[str, Any]:
        lightness, a, b = self.lab
        return {
            "hex": self.hex,
            "lab": {"L": lightness, "a": a, "b": b},
            "compressed": self.compressed,
        }


def draft_palettes(
    data: PaletteData | None = None,
    rules: ClassifierData | None = None,
) -> dict[str, Any]:
    """Every season's four groups, ready to be written out and seeded."""
    palette = data or load_palette_data()
    classifier = rules or load_classifier_data()

    seasons = [
        {
            "slug": slug,
            "sort": index,
            "point": _round_point(prototype(slug, classifier)),
            "groups": _groups(slug, palette, classifier),
        }
        for index, slug in enumerate(_ordered(classifier))
    ]

    return {
        "draft": palette.draft,
        "generator_version": palette.generator_version,
        "classifier_version": classifier.version,
        "generated_by": "color-engine palettes draft",
        "warning": (
            "Generated from the season prototypes, not chosen by a stylist, and not "
            "validated against anybody. Replace group by group as real palettes arrive; "
            "rows edited by a stylist are marked source=stylist and are never regenerated."
        ),
        "seasons": seasons,
    }


def _ordered(rules: ClassifierData) -> list[str]:
    """Seasons in a stable, human order: by leading axis, then by secondary.

    Sorted rather than left to dict order so the committed file does not reorder
    itself when somebody rewrites the JSON, and so ``sort`` in the database means
    something a person would recognise.
    """
    return sorted(
        rules.seasons.prototypes,
        key=lambda slug: (
            AXES.index(rules.seasons.prototypes[slug].leading[0]),
            -rules.seasons.prototypes[slug].leading[1],
            -rules.seasons.prototypes[slug].secondary[1],
        ),
    )


def _groups(slug: str, palette: PaletteData, rules: ClassifierData) -> list[dict[str, Any]]:
    point = prototype(slug, rules)
    flipped = _flip_leading(slug, point, rules)

    # Strict bands when the season is NAMED for its temperature.
    strict = rules.seasons.prototypes[slug].leading[0] == "temperature"

    groups = []
    for name in (*RECOMMENDED_GROUPS, AVOID_GROUP):
        spec = palette.groups[name]
        source = flipped if name == AVOID_GROUP else point
        swatches = _swatches(source, spec, palette, strict)
        groups.append(
            {
                "name": name,
                "recommended": spec.recommended,
                "point": _round_point(source),
                "colors": [
                    {"sort": index, **swatch.as_dict()} for index, swatch in enumerate(swatches)
                ],
            }
        )

    return groups


def _flip_leading(slug: str, point: dict[str, float], rules: ClassifierData) -> dict[str, float]:
    """The same season with the axis it is named for turned round - and its
    temperature with it.

    The leading axis, because that is what the season IS. Not all three: the
    diametrically opposite season is a different claim, and what a deep winter
    should avoid is light colours, not light *warm* ones.

    Temperature as well, because temperature no longer merely tints the palette
    - it decides which hues the season may have at all. An avoid group drawn
    from the same hue bands as the recommended one would be the right colours at
    the wrong lightness, which is not what drains a face. Every season here has
    temperature as its leading or its secondary characteristic, so there is
    always one to flip; when temperature IS the leading axis the two flips are
    the same one.
    """
    spec = rules.seasons.prototypes[slug]
    flipped = {name: -value if name == spec.leading[0] else value for name, value in point.items()}
    flipped["temperature"] = -point["temperature"]

    return flipped


def _swatches(
    point: dict[str, float], spec: GroupSpec, palette: PaletteData, strict: bool
) -> list[Swatch]:
    lightness = palette.lightness.centre - point["depth"] * palette.lightness.span
    hues = hue_set(point["temperature"], strict, palette, lightness)
    chroma = max(0.0, palette.chroma.centre + point["chroma"] * palette.chroma.span)

    spread = _fit_spread(
        lightness, palette.lightness.hue_spread + spec.lightness_spread, palette.lightness
    )

    swatches = []
    for index in range(spec.size):
        hue = hues[index % len(hues)]
        # Lightness follows hue: up towards yellow, down towards blue. sRGB has
        # no dark saturated yellow and no light saturated violet, so this keeps
        # chroma the compressor would otherwise have to take away.
        along_hue = math.cos(math.radians(hue - YELLOW_HUE))
        swatches.append(
            _swatch(lightness + spread * along_hue, chroma * spec.chroma_ratio, hue, palette)
        )

    return swatches


def hue_set(
    temperature: float, strict: bool, palette: PaletteData, lightness: float = 100.0
) -> list[float]:
    """The hues a season of this temperature may draw from, evenly spread.

    Temperature chooses the ARCS rather than turning a fixed wheel. Rotating six
    evenly spaced hues by 25 degrees, which is what this used to do, does not
    move a golden ochre out of a winter palette - it moves it to a slightly
    different ochre, and deep_winter came out holding #A5896B and #A87B45.

    The allowed arcs are concatenated and ``count`` hues are placed at even
    spacing along the total, so the palette covers whatever is left rather than
    clustering at one end. ``strict`` is for a season whose LEADING axis is
    temperature: where it is only secondary the bands open a few degrees at each
    edge, because the season is named for its depth or its clarity and the
    warm/cool call is a modifier.
    """
    stance = "warm" if temperature > 0 else "cool"
    # A hue band is not cool or warm on its own - it depends where the lightness
    # lands. Lemon reads cool while it is light; at the L* a deep season sits
    # at, the same hue is olive, which is warm. deep_winter's first accent came
    # out #748745 before this. Only `cool_deep` exists, because a deep WARM
    # season may have olive and moss; that is what it is for.
    if lightness < palette.hues.deep_lightness and f"{stance}_deep" in palette.hues.allowed:
        stance = f"{stance}_deep"
    arcs = palette.hues.allowed[stance]
    ranges = arcs.leading if strict else arcs.secondary

    total = sum(high - low for low, high in ranges)
    count = palette.hues.count
    hues: list[float] = []
    for index in range(count):
        # Half-step in, so the set does not sit on the arc boundaries - those
        # are exactly the hues the bands were drawn to exclude.
        position = (index + 0.5) / count * total
        for low, high in ranges:
            width = high - low
            if position <= width:
                hues.append((low + position) % 360.0)
                break
            position -= width
        else:  # pragma: no cover - the walk always lands inside a range
            hues.append(ranges[-1][1] % 360.0)

    return hues


def _fit_spread(centre: float, spread: float, bounds: LightnessSpec) -> float:
    """Narrow the lightness excursion until it fits, rather than clamping it.

    Clamping collapsed a light season's two lightest colours into the same pure
    white - a palette cannot contain one colour twice and still be six colours.
    Scaling keeps all six distinct and keeps their order, and it narrows exactly
    where a season has no room left: a light palette really is narrow at the top
    and a deep one at the bottom.
    """
    headroom = min(bounds.max - centre, centre - bounds.min)

    return max(0.0, min(spread, headroom))


def _swatch(lightness: float, chroma: float, hue: float, palette: PaletteData) -> Swatch:
    """One colour, brought inside sRGB by compressing its chroma.

    L* and hue are kept and only C* gives way. A garment is matched on hue and
    lightness; a colour that shifted hue to stay in gamut would be a different
    colour wearing the right name.
    """
    lightness = min(100.0, max(0.0, lightness))
    kept = _in_gamut_chroma(lightness, chroma, hue, palette.gamut)
    rgb = _to_rgb(lightness, kept, hue)

    # The LAB is taken back off the rounded 8-bit colour rather than from the
    # request, so the hex and the numbers beside it on the page are the same
    # colour. Storing the pre-rounding LAB would be storing a colour nobody can
    # display.
    quantised = np.clip(np.rint(rgb * 255.0), 0, 255).astype(np.int64)
    lab = colour.XYZ_to_Lab(colour.sRGB_to_XYZ(quantised / 255.0))

    return Swatch(
        hex="#" + "".join(format(int(channel), "02X") for channel in quantised),
        lab=(round(float(lab[0]), 2), round(float(lab[1]), 2), round(float(lab[2]), 2)),
        compressed=kept < chroma - palette.gamut.tolerance,
    )


def _in_gamut_chroma(lightness: float, chroma: float, hue: float, gamut: GamutSpec) -> float:
    """The largest fraction of the requested chroma that sRGB can show.

    Bisection with a fixed number of steps rather than a loop that stops when it
    is close enough: the file this feeds is committed and diffed, so the answer
    has to be the same on every machine, every time.
    """
    if chroma <= 0.0 or _inside(lightness, chroma, hue, gamut.tolerance):
        return chroma

    low, high = 0.0, chroma
    for _ in range(gamut.bisection_steps):
        middle = (low + high) / 2.0
        if _inside(lightness, middle, hue, gamut.tolerance):
            low = middle
        else:
            high = middle

    return low


def _inside(lightness: float, chroma: float, hue: float, tolerance: float) -> bool:
    rgb = _to_rgb(lightness, chroma, hue)

    return bool(np.all(rgb >= -tolerance) and np.all(rgb <= 1.0 + tolerance))


def _to_rgb(lightness: float, chroma: float, hue: float) -> Any:
    """LCh -> linear-encoded sRGB, possibly outside 0..1.

    The polar-to-rectangular step is spelled out rather than taken from
    ``colour`` because it is two lines of trigonometry and because the library's
    ``LCHab_to_Lab`` is not in its type stubs.
    """
    radians = math.radians(hue)
    lab = np.array(
        [lightness, chroma * math.cos(radians), chroma * math.sin(radians)], dtype=np.float64
    )

    return np.asarray(colour.XYZ_to_sRGB(colour.Lab_to_XYZ(lab)), dtype=np.float64)


def _round_point(point: dict[str, float]) -> dict[str, float]:
    return {axis: round(point[axis], 4) for axis in AXES}
