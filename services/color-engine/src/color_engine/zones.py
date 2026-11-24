"""Turning landmarks and the segmentation mask into the regions we measure.

Landmark indices are the MediaPipe Face Mesh canonical topology (468 mesh points
+ 10 iris points). Left/right follow **MediaPipe's own naming**, which is
subject-relative: the "left eye" is the person's left eye, which appears on the
right-hand side of a frontal photo.

The index sets below are a first pass. Every one of them is drawn into the HTML
report so they can be checked by eye and corrected; nothing downstream assumes
they are exact, because each skin polygon is intersected with the segmenter's
face-skin class and then run through outlier rejection.
"""

from __future__ import annotations

import math
from dataclasses import dataclass, field

import cv2
import numpy as np

from .arrays import AnyArray, MaskArray, PointArray, Shape2D
from .config import EngineConfig
from .detect import Detection, SegmentClass

# --- skin ------------------------------------------------------------------
# Cheeks. Sparse on purpose: we take the convex hull of the set and shrink it
# toward its centroid, so only the outer ring matters.
#
# Aimed at the apple of the cheek - the malar fullness over the cheekbone. Two
# things are deliberately excluded, for anatomy rather than for any one photo:
#
#   * the nasolabial fold: nothing from the 36/203/206 column, which runs down
#     the nose-wing crease and is in shadow on most faces;
#   * the lateral hollow under the zygomatic arch: 123 (and its mirror 352) sat
#     there, and a concavity is shaded under almost any lighting. 119/348, one
#     row up on the cheekbone itself, replaced it.
#
# 116/345 stay: they carry the outer half of the cheekbone, which is part of the
# apple, and they sit on the arch rather than below it.
RIGHT_CHEEK_POINTS = (116, 117, 118, 119, 101, 205, 50)  # subject's right
LEFT_CHEEK_POINTS = (345, 346, 347, 348, 330, 425, 280)  # mirror of the above

# Forehead: the brow line as the lower edge and the hairline arc as the upper
# one. The shrink pulls both edges inward, and intersecting with the face-skin
# class removes hair, a fringe or a headscarf edge that crosses the polygon.
FOREHEAD_POINTS = (
    # brow line, subject's right to left: 70..107 right brow, 9 glabella,
    # 336..300 left brow.
    70,
    63,
    105,
    66,
    107,
    9,
    336,
    296,
    334,
    293,
    300,
    # upper arc along the hairline, left back to right: 10 is top centre.
    332,
    297,
    338,
    10,
    109,
    67,
    103,
)

# --- eyes ------------------------------------------------------------------
# The palpebral fissure: the line where the lids meet, walked as an ordered ring
# so it can be filled directly without a hull.
#
# These are the FACEMESH_*_EYE contours, which follow the lid *margins* (the
# waterline) - deliberately not the socket ring one row out
# (130/247/30/29/27/28/56/190 and its mirror), which runs along the crease and
# would put eyeshadow and the whole upper lid inside the mask.
#
# Even the margin ring includes the lashes, though, which is why the sclera is
# cut down to the wedges beside the iris and then filtered by chroma - see
# _sclera_mask below and sampling.sample_sclera.
RIGHT_EYE_OPENING = (
    33,
    7,
    163,
    144,
    145,
    153,
    154,
    155,
    133,
    173,
    157,
    158,
    159,
    160,
    161,
    246,
)
LEFT_EYE_OPENING = (
    362,
    382,
    381,
    380,
    374,
    373,
    390,
    249,
    263,
    466,
    388,
    387,
    386,
    385,
    384,
    398,
)

# Eye corners, inner then outer. They define the eye's own axis, which is what
# the sclera wedges are measured along - so a tilted head does not shift them.
RIGHT_EYE_CORNERS = (133, 33)
LEFT_EYE_CORNERS = (362, 263)

# Iris: index 0 of each tuple is the centre, the other four are the ring that
# gives us the radius.
RIGHT_IRIS = (468, 469, 470, 471, 472)
LEFT_IRIS = (473, 474, 475, 476, 477)

SKIN_ZONES = ("left_cheek", "right_cheek", "forehead")
SMALL_ZONES = ("iris_left", "iris_right", "sclera_left", "sclera_right", "iris", "sclera")

TOO_FEW_PIXELS = "too_few_pixels"
EYE_TOO_SMALL = "eye_too_small"

HAIR_OCCLUDED = "hair_occluded"
"""Headphones, a hat or a band over most of the hair. What is left is a fringe
and an edge, not somebody's hair colour."""
"""The iris was below ``min_iris_diameter_px`` in the ORIGINAL frame. Not a
rejection: the eye zones drop out and the skin analysis carries on, because a
photo taken a little too far away still measures skin perfectly well."""


@dataclass(frozen=True)
class ZoneSpec:
    """Where a zone's geometry comes from.

    The report prints this verbatim - constant name, indices, point count - next
    to the resulting mask area, so a wrong index can be spotted and corrected
    without reading the source.
    """

    zone: str
    constant: str | None
    indices: tuple[int, ...]
    note: str


ZONE_SPECS: tuple[ZoneSpec, ...] = (
    ZoneSpec(
        "right_cheek",
        "RIGHT_CHEEK_POINTS",
        RIGHT_CHEEK_POINTS,
        "convex hull, shrunk to centroid, AND face-skin class, eroded",
    ),
    ZoneSpec(
        "left_cheek",
        "LEFT_CHEEK_POINTS",
        LEFT_CHEEK_POINTS,
        "convex hull, shrunk to centroid, AND face-skin class, eroded",
    ),
    ZoneSpec(
        "forehead",
        "FOREHEAD_POINTS",
        FOREHEAD_POINTS,
        "convex hull, shrunk to centroid, AND face-skin class, eroded",
    ),
    ZoneSpec(
        "skin",
        None,
        (),
        "union of the three sub-zones (mask only; samples.skin is the median of their medians)",
    ),
    ZoneSpec(
        "hair",
        None,
        (),
        "segmenter class HAIR, eroded by hair_erode_ratio, minus the accessory class dilated by "
        "accessory_dilate_ratio",
    ),
    ZoneSpec(
        "background",
        None,
        (),
        "segmenter class BACKGROUND, eroded by scene_erode_ratio - the grey-world fallback for "
        "white balance. Clothes are deliberately excluded: one saturated dress would drag the "
        "estimate to its complement",
    ),
    ZoneSpec(
        "iris_right",
        "RIGHT_IRIS",
        RIGHT_IRIS,
        "annulus between iris_inner_ratio and iris_outer_ratio, clipped to the eye opening",
    ),
    ZoneSpec(
        "iris_left",
        "LEFT_IRIS",
        LEFT_IRIS,
        "annulus between iris_inner_ratio and iris_outer_ratio, clipped to the eye opening",
    ),
    ZoneSpec("iris", None, (), "both irises"),
    ZoneSpec(
        "sclera_right",
        "RIGHT_EYE_OPENING",
        RIGHT_EYE_OPENING,
        "wedges of the opening beside the iris, measured along the eye axis, then eroded",
    ),
    ZoneSpec(
        "sclera_left",
        "LEFT_EYE_OPENING",
        LEFT_EYE_OPENING,
        "wedges of the opening beside the iris, measured along the eye axis, then eroded",
    ),
    ZoneSpec("sclera", None, (), "both scleras"),
)

ZONE_SPEC_BY_NAME: dict[str, ZoneSpec] = {spec.zone: spec for spec in ZONE_SPECS}

LANDMARKS_IN_USE: frozenset[int] = frozenset(index for spec in ZONE_SPECS for index in spec.indices)
"""Every landmark any zone depends on - what ``--landmark-labels zones`` draws."""


@dataclass(frozen=True)
class Zone:
    """A binary mask plus enough context to explain itself in the report."""

    name: str
    mask: MaskArray  # 0 or 255
    pixels: int
    sufficient: bool
    polygon: PointArray | None = None  # for the debug overlay
    spec: ZoneSpec | None = None
    reason: str | None = None
    """Why the zone is unusable: ``too_few_pixels`` or ``eye_too_small``. None
    when it is fine. The report prints it - "insufficient" on its own tells the
    reviewer nothing they can act on."""

    @property
    def usable(self) -> bool:
        return self.sufficient and self.pixels > 0


@dataclass(frozen=True)
class Zones:
    zones: dict[str, Zone] = field(default_factory=dict)

    def get(self, name: str) -> Zone | None:
        zone = self.zones.get(name)
        return zone if zone is not None and zone.usable else None

    @property
    def insufficient(self) -> list[str]:
        return sorted(name for name, z in self.zones.items() if not z.usable)

    @property
    def reasons(self) -> dict[str, str]:
        """Why each unusable zone is unusable, by name."""
        return {
            name: zone.reason or TOO_FEW_PIXELS
            for name, zone in sorted(self.zones.items())
            if not zone.usable
        }


def build_zones(detection: Detection, config: EngineConfig) -> Zones:
    """Build every measurement zone for a detected face.

    Zones that end up too small are kept, flagged ``sufficient=False``. Nothing
    here raises: a missing zone is a measurement we skip, not a failure.
    """
    height, width = detection.category_mask.shape[:2]
    mask_shape: Shape2D = (int(height), int(width))
    points = detection.landmarks
    zones: dict[str, Zone] = {}

    face_skin = (detection.category_mask == SegmentClass.FACE_SKIN).astype(np.uint8) * 255
    skin_kernel = _kernel(detection.face_width, config.skin_erode_ratio)

    skin_masks: list[MaskArray] = []
    for name, indices in (
        ("right_cheek", RIGHT_CHEEK_POINTS),
        ("left_cheek", LEFT_CHEEK_POINTS),
        ("forehead", FOREHEAD_POINTS),
    ):
        polygon = _hull_polygon(points, indices, config.zone_polygon_shrink)
        mask = _fill(mask_shape, polygon)
        # The intersection is what makes the hand-picked polygons safe: hair on
        # the forehead, glasses frames and beard are all a different class.
        mask = cv2.bitwise_and(mask, face_skin)
        mask = _erode(mask, skin_kernel)
        zones[name] = _zone(name, mask, config.min_zone_pixels, polygon)
        skin_masks.append(mask)

    zones["skin"] = _zone("skin", _union(skin_masks, mask_shape), config.min_zone_pixels, None)

    # Hair borders both skin and background, and the segmenter is soft on both
    # edges, so this erosion is deliberately the largest one we apply.
    hair, hair_reason = _hair_mask(detection, config)
    zones["hair"] = _zone("hair", hair, config.min_zone_pixels, None, hair_reason)

    # The scene behind the person: white balance's fallback reference. Eroded
    # like hair and for the same reason - the segmenter is soft on the outline,
    # and the halo it leaves there is hair and skin, not background.
    background = (detection.category_mask == SegmentClass.BACKGROUND).astype(np.uint8) * 255
    background = _erode(background, _kernel(detection.face_width, config.scene_erode_ratio))
    zones["background"] = _zone("background", background, config.scene_min_pixels, None)

    # Only the eyes that passed their own checks go into the pooled masks. An
    # eye rejected for any reason - too small in the source frame, too few
    # pixels after masking - is rejected because we do not believe its pixels,
    # and pooling them in anyway would put exactly those pixels into
    # ``samples.iris`` and ``samples.sclera``, which are the contract.
    iris_masks: list[MaskArray] = []
    sclera_masks: list[MaskArray] = []
    undersized: list[bool] = []
    for side, iris_idx, opening_idx, corners_idx in (
        ("right", RIGHT_IRIS, RIGHT_EYE_OPENING, RIGHT_EYE_CORNERS),
        ("left", LEFT_IRIS, LEFT_EYE_OPENING, LEFT_EYE_CORNERS),
    ):
        centre, radius = _iris_circle(points, iris_idx)
        eye_polygon = _polygon(points, opening_idx)
        # Measured back at source resolution: what matters is how many pixels
        # the camera put on the eye, not how many survived our own downscale.
        small = 2.0 * radius * detection.source_scale < config.min_iris_diameter_px
        undersized.append(small)
        eye_reason = EYE_TOO_SMALL if small else None

        iris_mask = _iris_annulus(mask_shape, centre, radius, eye_polygon, config)
        iris_zone = _zone(f"iris_{side}", iris_mask, config.min_small_zone_pixels, None, eye_reason)
        zones[f"iris_{side}"] = iris_zone
        if iris_zone.usable:
            iris_masks.append(iris_mask)

        sclera_mask = _sclera_mask(
            mask_shape, eye_polygon, _polygon(points, corners_idx), centre, radius, config
        )
        sclera_zone = _zone(
            f"sclera_{side}", sclera_mask, config.min_small_zone_pixels, eye_polygon, eye_reason
        )
        zones[f"sclera_{side}"] = sclera_zone
        if sclera_zone.usable:
            sclera_masks.append(sclera_mask)

    # With one eye left the pooled zone *is* that eye, which is the right
    # answer: one good eye is still a measurement.
    pooled_reason = EYE_TOO_SMALL if all(undersized) else None
    zones["iris"] = _zone(
        "iris", _union(iris_masks, mask_shape), config.min_small_zone_pixels, None, pooled_reason
    )
    zones["sclera"] = _zone(
        "sclera",
        _union(sclera_masks, mask_shape),
        config.min_small_zone_pixels,
        None,
        pooled_reason,
    )

    return Zones(zones=zones)


# --- geometry helpers ------------------------------------------------------


def _polygon(points: PointArray, indices: tuple[int, ...]) -> PointArray:
    return np.asarray([points[i] for i in indices], dtype=np.float32)


def _hull_polygon(points: PointArray, indices: tuple[int, ...], shrink: float) -> PointArray:
    """Convex hull of the picked landmarks, scaled toward its centroid.

    The hull means the order of the index tuple does not matter, and a single
    misplaced index cannot invert the polygon - it can only widen it slightly.
    """
    hull = np.asarray(
        cv2.convexHull(_polygon(points, indices).reshape(-1, 1, 2)), dtype=np.float32
    ).reshape(-1, 2)
    centroid = hull.mean(axis=0)
    return np.asarray((hull - centroid) * shrink + centroid, dtype=np.float32)


def _ring(
    shape: Shape2D, centre: PointArray, inner: float, outer: float
) -> tuple[AnyArray, AnyArray]:
    """Pixel coordinates of an annulus, at sub-pixel accuracy.

    Returns an index pair rather than a mask so the caller decides what to do
    with it; the arithmetic runs over the circle's bounding box, not the frame.
    """
    height, width = shape
    cx, cy = float(centre[0]), float(centre[1])
    x0, x1 = max(0, int(np.floor(cx - outer))), min(width, int(np.ceil(cx + outer)) + 1)
    y0, y1 = max(0, int(np.floor(cy - outer))), min(height, int(np.ceil(cy + outer)) + 1)
    if x1 <= x0 or y1 <= y0:  # pragma: no cover - the eye is off the frame
        return np.zeros(0, dtype=np.intp), np.zeros(0, dtype=np.intp)

    yy, xx = np.mgrid[y0:y1, x0:x1]
    distance = np.hypot(xx - cx, yy - cy)
    inside = (distance <= outer) & (distance >= inner)

    return yy[inside], xx[inside]


def _shrink_polygon(polygon: PointArray, shrink: float) -> PointArray:
    """Scale a polygon toward its own centroid.

    Scale-free by construction, unlike eroding the mask it would fill: no
    integer kernel, so the same shape at any resolution.
    """
    centroid = polygon.mean(axis=0)

    return np.asarray((polygon - centroid) * shrink + centroid, dtype=np.float32)


def _fill(shape: Shape2D, polygon: PointArray) -> MaskArray:
    mask = np.zeros(shape, dtype=np.uint8)
    cv2.fillPoly(mask, [np.round(polygon).astype(np.int32)], 255)
    return mask


def _union(masks: list[MaskArray], shape: Shape2D) -> MaskArray:
    out = np.zeros(shape, dtype=np.uint8)
    for mask in masks:
        out = np.asarray(cv2.bitwise_or(out, mask), dtype=np.uint8)
    return out


def _kernel(face_width: float, ratio: float) -> MaskArray | None:
    """An elliptical kernel sized as a fraction of the face, never in raw pixels.

    A constant pixel kernel would erode a 1600px photo differently from an 800px
    one; zones have to be resolution-independent.
    """
    size = round(face_width * ratio)
    if size < 1:
        return None
    size = size if size % 2 == 1 else size + 1
    return np.asarray(cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (size, size)), dtype=np.uint8)


def _erode(mask: MaskArray, kernel: MaskArray | None) -> MaskArray:
    if kernel is None:
        return mask
    return np.asarray(cv2.erode(mask, kernel, iterations=1), dtype=np.uint8)


def _iris_circle(points: PointArray, indices: tuple[int, ...]) -> tuple[PointArray, float]:
    centre = np.asarray(points[indices[0]], dtype=np.float32)
    ring = _polygon(points, indices[1:])
    radius = float(np.mean(np.linalg.norm(ring - centre, axis=1)))
    return centre, radius


def _iris_annulus(
    shape: Shape2D,
    centre: PointArray,
    radius: float,
    opening: PointArray,
    config: EngineConfig,
) -> MaskArray:
    """The ring of iris that is actually visible.

    Three things are taken out, and the first live frame needed all three. The
    PUPIL, with room for it to be dilated - indoors it is much wider than in
    daylight, so a ratio tuned on a bright photo puts black into the sample of
    every dim one. The LIMBAL RING, the dark band at the edge of every iris,
    which is not the eye's colour and is dark enough to drag a median a long
    way. And whatever the EYELID is covering: on most open eyes the lid crosses
    the top of the iris, and the pixels under it are lid and lash, or the shadow
    they cast.

    That frame read L* 17.7 on light eyes. It was measuring all three.
    """
    mask = np.zeros(shape, dtype=np.uint8)
    outer = radius * config.iris_outer_ratio
    inner = radius * config.iris_inner_ratio
    if outer < 1.0:
        return mask

    # Drawn from a distance field rather than with two cv2.circle calls, because
    # those take an INTEGER radius. Rounding both edges of a ring this thin
    # changes its area by more than a tenth between a photo at 800px and the
    # same one at 1600 - the annulus is only about a quarter of the iris radius
    # wide, so the rounding is a large fraction of it. A distance test has no
    # such step, and is computed over the eye's own bounding box rather than the
    # frame, so it costs nothing.
    mask[_ring(shape, centre, inner, outer)] = 255

    # Only what the fissure leaves visible, with its edge pulled in so the lash
    # line does not come with it.
    #
    # Pulled in by SCALING the polygon rather than by eroding the filled mask,
    # which is what the sclera does. Erosion needs an integer kernel, and
    # rounding it makes the margin a slightly different fraction of the eye at
    # different resolutions - invisible on a broad zone, but this annulus is a
    # thin ring sitting right at that boundary, so the same photo at 800px and
    # at 1600px would come out a different shape. A polygon scaled toward its
    # own centroid has no such rounding.
    visible = _fill(shape, _shrink_polygon(opening, config.iris_opening_shrink))

    return np.asarray(cv2.bitwise_and(mask, visible), dtype=np.uint8)


def _hair_mask(detection: Detection, config: EngineConfig) -> tuple[MaskArray, str | None]:
    """The hair class with accessories cut out of it.

    Headphones, a hat, a hairband: the segmenter has a class for them, and the
    hair mask runs right up to its edge - so the band where the segmenter is
    least certain lies exactly along the boundary we care about. The pixels it
    gets wrong there are dark and near-neutral, which is to say they look like
    hair to every measurement downstream. A live frame of somebody in headphones
    measured hair at L* 12.2, C* 3.0.

    The accessory class is DILATED before it is subtracted, by more than the
    hair is eroded. Losing a little real hair costs a few pixels off a zone that
    has thousands; keeping a little plastic costs the depth axis, which weights
    hair at 0.5.

    **How much is too much** is measured over the head rather than over the hair,
    and the difference matters. A pixel belongs to exactly one segmenter class,
    so hair UNDER a headphone cup is classed as accessory and never counted as
    hair at all - measuring "how much of the hair did we lose" against the hair
    that survived answers a question nobody asked, and comes out small however
    much is covered. What is measured instead is the share of the head's visible
    surface taken up by things being worn: the accessory blobs that touch the
    hair, against those blobs plus the hair. Blobs rather than a rim, because a
    headphone cup is one connected object; and touching the hair, because a pair
    of glasses is also class OTHERS and has nothing to do with this.
    """
    hair = (detection.category_mask == SegmentClass.HAIR).astype(np.uint8) * 255
    # Hair is on the head. Below the chin the segmenter's HAIR class is often a
    # dark top or jacket, and it drags the hair colour towards black.
    _, face_top, _, face_height = detection.face_bbox
    limit = int(math.ceil(face_top + face_height * (1.0 + config.hair_below_chin_ratio)))
    hair[max(0, limit) :, :] = 0
    eroded = _erode(hair, _kernel(detection.face_width, config.hair_erode_ratio))

    accessories = (detection.category_mask == SegmentClass.OTHERS).astype(np.uint8) * 255
    if int(np.count_nonzero(accessories)) == 0:
        return eroded, None

    on_head = _accessories_on_head(hair, accessories, detection, config)
    grown = _dilate(on_head, _kernel(detection.face_width, config.accessory_dilate_ratio))
    kept = np.asarray(cv2.bitwise_and(eroded, cv2.bitwise_not(grown)), dtype=np.uint8)

    worn = int(np.count_nonzero(on_head))
    head = int(np.count_nonzero(hair)) + worn
    if head and worn / head > config.max_accessory_over_head:
        return kept, HAIR_OCCLUDED

    return kept, None


def _accessories_on_head(
    hair: MaskArray, accessories: MaskArray, detection: Detection, config: EngineConfig
) -> MaskArray:
    """Accessory blobs that touch the hair, whole.

    Whole blobs and not the overlapping rim: a headphone cup is one connected
    object, and half of it being away from the hair does not make that half
    somebody's hair colour. Touching the hair, because glasses are class OTHERS
    too and are not what this is about.
    """
    near_hair = _dilate(hair, _kernel(detection.face_width, config.accessory_dilate_ratio))
    count, labels = cv2.connectedComponents((accessories > 0).astype(np.uint8))
    touching = set(np.unique(labels[near_hair > 0])) - {0}
    if not touching:
        return np.zeros_like(accessories)

    keep = np.isin(labels, list(touching)) & (accessories > 0)

    return np.asarray(keep.astype(np.uint8) * 255, dtype=np.uint8)


def _dilate(mask: MaskArray, kernel: MaskArray | None) -> MaskArray:
    if kernel is None:
        return mask

    return np.asarray(cv2.dilate(mask, kernel), dtype=np.uint8)


def _sclera_mask(
    shape: Shape2D,
    opening: PointArray,
    corners: PointArray,
    iris_centre: PointArray,
    iris_radius: float,
    config: EngineConfig,
) -> MaskArray:
    """The white of the eye: the wedges medial and lateral to the iris.

    Not "the opening minus the iris disc". On most open eyes the iris is nearly
    as tall as the fissure, so everything left above and below it is lid margin
    and lashes - which is exactly what an earlier version measured. The sclera
    that is actually visible sits *beside* the iris, so we keep only pixels far
    enough from the iris centre **along the eye's own axis**.

    Measuring along the eye axis rather than horizontally means a tilted head
    does not shift the wedges off the sclera.

    The wedges also stop SHORT of both corners. The landmark opening runs corner
    to corner; the white does not. The caruncle sits inside the inner corner and
    the lid margins converge onto skin at the outer one, and a wedge that runs
    the full distance collects both. That matters more than it sounds: the a*b*
    filter downstream takes its reference from the median of whatever it is
    given, so once corner skin is the majority of the wedge the reference
    becomes skin - and the filter then keeps the skin and discards the sclera.
    The sample that comes out really is not brighter than the skin, because it
    is the skin.
    """
    mask = _fill(shape, opening)

    axis = np.asarray(corners[1] - corners[0], dtype=np.float64)
    norm = float(np.linalg.norm(axis))
    if norm <= 1e-6:  # pragma: no cover - degenerate landmarks
        return mask
    axis /= norm
    normal = np.asarray([-axis[1], axis[0]], dtype=np.float64)

    ys, xs = np.nonzero(mask)
    if ys.size == 0:
        return mask
    offsets = np.stack([xs, ys], axis=1).astype(np.float64) - np.asarray(
        iris_centre, dtype=np.float64
    )
    along = offsets @ axis
    limit = iris_radius * (1.0 + config.sclera_iris_margin)

    # How far the corners sit along the axis. `corners` is (inner, outer) and
    # `axis` runs from the first to the second, so the inner corner is at
    # negative `along` and the outer at positive.
    corner_offsets = (corners.astype(np.float64) - np.asarray(iris_centre, dtype=np.float64)) @ axis
    inner_reach = abs(float(corner_offsets[0])) * (1.0 - config.sclera_inner_corner_cut)
    outer_reach = abs(float(corner_offsets[1])) * (1.0 - config.sclera_outer_corner_cut)

    # Zeroing by distance along the axis subsumes removing the iris disc: every
    # iris pixel is within `limit` of the centre along that axis too. The two
    # reaches cut the other end of each wedge, off the caruncle and off the
    # outer join.
    drop = (
        (np.abs(along) < limit)
        | ((along < 0.0) & (-along > inner_reach))
        | ((along > 0.0) & (along > outer_reach))
    )
    mask[ys[drop], xs[drop]] = 0

    # Erode by a fraction of the fissure's *height*, not of the face: a squint
    # and a wide-open eye need different margins on the same face.
    across = (opening.astype(np.float64) - np.asarray(iris_centre, dtype=np.float64)) @ normal
    opening_height = float(across.max() - across.min())
    return _erode(mask, _kernel(opening_height, config.sclera_erode_ratio))


def _to_int(point: PointArray) -> tuple[int, int]:
    return (round(float(point[0])), round(float(point[1])))


def _zone(
    name: str,
    mask: MaskArray,
    minimum: int,
    polygon: PointArray | None,
    reason: str | None = None,
) -> Zone:
    pixels = int(cv2.countNonZero(mask))
    enough = pixels >= minimum
    return Zone(
        name=name,
        mask=mask,
        pixels=pixels,
        sufficient=enough and reason is None,
        polygon=None if polygon is None else np.round(polygon).astype(np.float32),
        spec=ZONE_SPEC_BY_NAME.get(name),
        reason=reason if reason is not None else (None if enough else TOO_FEW_PIXELS),
    )
