"""Debug images as a public API.

The overlay and the zone close-ups used to exist only inside the report builder.
Anything else that wanted them had to reach into a private module, so these
tests pin the shape of the seam: which names come back, when a name is absent,
and that the bytes are really PNG.
"""

from __future__ import annotations

import io

import cv2
import numpy as np
import pytest
from PIL import Image

from color_engine import IMAGE_NAMES, debug_images, render_debug_images
from color_engine.config import EngineConfig
from color_engine.debug import DebugArtifacts, LandmarkLabels
from color_engine.images import CROP_IMAGES, OVERLAY, SOURCE_PREVIEW, WB_BEFORE_AFTER
from color_engine.models import AnalysisResult, RejectReason, Status
from color_engine.sampling import sample_zones
from color_engine.zones import build_zones

from .test_sclera_pooling import _face
from .test_zones import _synthetic_detection

PNG_MAGIC = b"\x89PNG\r\n\x1a\n"


def _result(status: Status = Status.OK) -> AnalysisResult:
    return AnalysisResult(
        status=status,
        reject_reason=None if status is Status.OK else RejectReason.NO_FACE,
        algo_version="0.1.0",
    )


def _artifacts(config: EngineConfig, *, corrected: bool = False) -> DebugArtifacts:
    detection = _synthetic_detection()
    zones = build_zones(detection, config)
    debug = DebugArtifacts()
    debug.landmarks = detection.landmarks
    debug.face_bbox = detection.face_bbox
    debug.face_count = 1
    debug.category_mask = detection.category_mask
    debug.record_zones(zones)

    # A face with something to measure in it, so the sampled views have pixels
    # to show. A flat grey frame would render empty ones and prove nothing.
    size = detection.category_mask.shape[0]
    rgb = np.full((size, size, 3), (198, 154, 126), dtype=np.uint8)
    for centre in ((300, 335), (500, 335)):
        cv2.ellipse(rgb, centre, (46, 24), 0, 0, 360, (232, 230, 226), thickness=-1)
        cv2.circle(rgb, centre, 18, (74, 104, 132), thickness=-1)
    rgb[:200] = (48, 34, 26)  # hair

    debug.converted_rgb = rgb
    debug.sampled_masks = sample_zones(rgb, zones, config, keep_masks=True).sampled_masks
    if corrected:
        debug.corrected_rgb = np.full((size, size, 3), 140, dtype=np.uint8)

    return debug


def test_every_view_is_rendered_for_a_measured_photo(config: EngineConfig) -> None:
    images = debug_images(_result(), _artifacts(config, corrected=True))

    assert set(images) == set(IMAGE_NAMES)
    assert all(image.ndim == 3 and image.shape[2] == 3 for image in images.values())


def test_the_names_are_the_subjects_left_and_right() -> None:
    """Left and right are the subject's everywhere else in this package, so a
    consumer reading crop_left_eye gets the eye on the right of the photo."""
    assert dict(CROP_IMAGES)["crop_left_eye"] == "iris_left"
    assert dict(CROP_IMAGES)["crop_right_cheek"] == "right_cheek"


def test_an_uncorrected_photo_has_no_before_and_after(config: EngineConfig) -> None:
    """Absent, not blank. There is nothing to compare when nothing was applied."""
    images = debug_images(_result(), _artifacts(config, corrected=False))

    assert WB_BEFORE_AFTER not in images
    assert OVERLAY in images


def test_the_before_and_after_is_one_image_of_both(config: EngineConfig) -> None:
    debug = _artifacts(config, corrected=True)
    images = debug_images(_result(), debug)

    pair = images[WB_BEFORE_AFTER]
    assert debug.converted_rgb is not None
    assert pair.shape[0] == debug.converted_rgb.shape[0]
    assert pair.shape[1] > 2 * debug.converted_rgb.shape[1], "both halves plus a separator"


def test_a_photo_rejected_before_the_zones_still_gets_a_preview(
    config: EngineConfig,
) -> None:
    """No zones, so no overlay and no crops - but the photograph itself is
    exactly what somebody looking at a refusal needs to see, and it may have
    arrived as HEIC, which no browser will render."""
    debug = DebugArtifacts()
    debug.converted_rgb = np.full((60, 60, 3), 128, dtype=np.uint8)

    images = debug_images(_result(Status.REJECTED), debug)

    assert set(images) == {SOURCE_PREVIEW}


def test_the_preview_is_the_frame_the_engine_measured(config: EngineConfig) -> None:
    """After EXIF rotation and the ICC conversion to sRGB - not a thumbnail of
    the file. A preview that disagreed with the measurement would be worse than
    none, because it is the thing a reviewer checks the numbers against."""
    debug = DebugArtifacts()
    debug.converted_rgb = np.full((40, 50, 3), 90, dtype=np.uint8)

    preview = debug_images(_result(), debug)[SOURCE_PREVIEW]

    assert np.array_equal(preview, debug.converted_rgb)


def test_no_debug_artifacts_at_all() -> None:
    assert debug_images(_result(), None) == {}
    assert render_debug_images(_result(), None) == {}


def test_the_bytes_are_png(config: EngineConfig) -> None:
    """PNG, not JPEG: these pictures exist to show which pixels a mask covered,
    and JPEG ringing around a mask edge is exactly the artefact that would be
    mistaken for a mask defect."""
    rendered = render_debug_images(_result(), _artifacts(config, corrected=True))

    assert set(rendered) == set(IMAGE_NAMES)
    for name, payload in rendered.items():
        assert payload.startswith(PNG_MAGIC), name
        with Image.open(io.BytesIO(payload)) as decoded:
            assert decoded.format == "PNG"
            assert decoded.size[0] > 0


def test_landmark_labels_reach_the_overlay(config: EngineConfig) -> None:
    debug = _artifacts(config)

    plain = debug_images(_result(), debug, LandmarkLabels.NONE)[OVERLAY]
    labelled = debug_images(_result(), debug, LandmarkLabels.ZONES)[OVERLAY]

    assert not np.array_equal(plain, labelled)


@pytest.mark.parametrize("name", IMAGE_NAMES)
def test_each_name_is_renderable_on_its_own(name: str, config: EngineConfig) -> None:
    assert name in render_debug_images(_result(), _artifacts(config, corrected=True))


def test_the_report_draws_the_same_pictures(config: EngineConfig, tmp_path: object) -> None:
    """The report is a consumer of this module, not a second implementation."""
    from color_engine.report.builder import CROP_CAPTIONS, build_entry

    debug = _artifacts(config, corrected=True)
    result = _result().attach_diagnostics(debug, {"load": 1.0})

    entry = build_entry("a.png", b"", result)

    assert "overlay" in entry.images
    assert {caption for caption, _ in entry.crops} == set(CROP_CAPTIONS.values())


# --- what was actually measured -------------------------------------------


def test_the_sampled_view_shows_only_the_pixels_behind_the_number(
    config: EngineConfig,
) -> None:
    """Not the zone's mask — where we looked — but the pixels that survived
    every filter. A zone can sit perfectly on the overlay and still have had
    three quarters of itself thrown away by a trim nobody can see."""
    debug = _artifacts(config)

    view = debug_images(_result(), debug)["sampled_forehead"]
    kept = debug.sampled_masks["forehead"]
    looked = debug.zone_masks["forehead"]

    assert kept.any()
    assert not np.any(kept & ~looked), "it can only have measured where it looked"
    assert view.ndim == 3


def test_a_trim_that_removes_pixels_shows_up_in_the_view(config: EngineConfig) -> None:
    """On a flat synthetic patch the percentile trim keeps everything, which is
    correct and proves nothing. Give the forehead a gradient and the difference
    between "where we looked" and "what we measured" appears."""
    debug = _artifacts(config)
    assert debug.converted_rgb is not None
    rgb = debug.converted_rgb.copy()
    for row in range(rgb.shape[0]):
        shade = 120 + (row % 90)
        rgb[row, :] = (shade, int(shade * 0.78), int(shade * 0.64))

    from color_engine.sampling import sample_zones
    from color_engine.zones import build_zones

    from .test_zones import _synthetic_detection

    zones = build_zones(_synthetic_detection(), config)
    kept = sample_zones(rgb, zones, config, keep_masks=True).sampled_masks["forehead"]

    assert int(np.count_nonzero(kept)) < int(np.count_nonzero(zones.zones["forehead"].mask))


def test_rejected_pixels_are_pushed_back_rather_than_blanked(config: EngineConfig) -> None:
    """The anatomy has to stay readable, or a reviewer cannot judge whether what
    was excluded should have been."""
    debug = _artifacts(config)

    view = debug_images(_result(), debug)["sampled_skin"]

    assert view.min() > 0, "nothing is painted out entirely"
    assert view.max() > view.mean(), "the kept pixels are still the bright part"


def test_the_skin_view_is_the_union_of_the_sub_zones(config: EngineConfig) -> None:
    """samples.skin is the median of three sub-zone medians, not a measurement
    of the union mask — so the pixels behind it are those three and nothing
    else."""
    debug = _artifacts(config)

    union = np.maximum.reduce(
        [debug.sampled_masks[name] for name in ("left_cheek", "right_cheek", "forehead")]
    )

    assert np.array_equal(debug.sampled_masks["skin"], union)


def test_a_zone_that_produced_no_sample_has_no_sampled_view(config: EngineConfig) -> None:
    debug = _artifacts(config)
    debug.sampled_masks.pop("hair")

    assert "sampled_hair" not in debug_images(_result(), debug)


def test_crops_are_outlined_not_filled(config: EngineConfig) -> None:
    """A filled overlay paints over the pixels, so a close-up of an eye would
    show the colour we chose for the zone rather than the colour of the eye —
    the one thing a close-up of an eye exists to show."""
    from color_engine.debug import ZONE_COLORS, render_outlines, render_overlay

    debug = _artifacts(config)
    assert debug.converted_rgb is not None

    filled = render_overlay(debug.converted_rgb, debug)
    outlined = render_outlines(debug.converted_rgb, debug)
    # A broad zone, so the contour line itself is a small share of it.
    inside = debug.zone_masks["forehead"] > 0

    unchanged = np.all(outlined[inside] == debug.converted_rgb[inside], axis=1)
    assert unchanged.mean() > 0.9, "the inside of the zone should be the photo"
    assert not np.array_equal(filled[inside], outlined[inside])
    assert ZONE_COLORS["forehead"]


def test_a_refusal_after_the_zones_renders_everything_it_can(config: EngineConfig) -> None:
    """A refused frame is when the views matter most.

    Somebody looking at "uneven light" or "overexposed" needs to see which
    pixels the engine measured to say so. The rendering path does not consult
    the status at all, and this is the test that keeps it that way.
    """
    detection = _synthetic_detection()
    image = _face(detection)
    zones = build_zones(detection, config)
    measured = sample_zones(image, zones, config, keep_masks=True)

    debug = DebugArtifacts()
    debug.converted_rgb = image
    debug.landmarks = detection.landmarks
    debug.face_bbox = detection.face_bbox
    debug.record_zones(zones)
    debug.sampled_masks = measured.sampled_masks

    refused = debug_images(
        AnalysisResult(
            status=Status.REJECTED,
            reject_reason=RejectReason.UNEVEN_LIGHT,
            algo_version="0.1.0",
        ),
        debug,
    )
    measured_ok = debug_images(_result(), debug)

    assert set(refused) == set(measured_ok)
    # Everything but the before/after, which needs a correction that a refused
    # frame never got.
    assert set(refused) == set(IMAGE_NAMES) - {WB_BEFORE_AFTER}
