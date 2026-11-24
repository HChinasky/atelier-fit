"""The pipeline.

``Engine`` owns the two MediaPipe models and runs the steps in order:

    load -> detect -> zones -> sample -> white_balance -> quality
         -> features -> classify

Each step is a plain function living in its own module, taking explicit
arguments and returning a typed object. No dict is passed from end to end - when
a step needs something, it says so in its signature.

Threading
---------
**One Engine per process or worker.** MediaPipe Tasks objects are not
thread-safe, and there is no global engine singleton in this package on purpose:
importing the library must never load 20 MB of models. ``analyze`` takes an
internal lock so a stray second thread corrupts nothing, but that serialises
work - it is a safety net, not a concurrency story.
"""

from __future__ import annotations

import logging
import threading
import time
from collections.abc import Iterator
from contextlib import contextmanager
from types import TracebackType
from typing import Any

from .classify import classify
from .config import EngineConfig
from .debug import DebugArtifacts
from .detect import create_face_landmarker, create_segmenter, detect, segment_categories
from .errors import ImageInputError
from .features import compute_features
from .garment import GarmentResult, analyze_garment
from .io import load_image
from .models import (
    AnalysisResult,
    Quiz,
    RejectReason,
    Samples,
    Status,
    WhiteBalance,
    ZoneStatus,
)
from .quality import quality_gate
from .sampling import sample_zones, zone_statuses
from .white_balance import adapt_image, apply_white_balance, white_balance_info
from .zones import build_zones

logger = logging.getLogger("color_engine")

try:  # pragma: no cover - trivial
    from importlib.metadata import PackageNotFoundError, version

    __version__ = version("color-engine")
except (ImportError, PackageNotFoundError):  # pragma: no cover - source checkout
    __version__ = "0.1.0"


class Engine:
    """Loads the models once, then analyses photos.

    Use it as a context manager, or call ``close()`` when done::

        with Engine() as engine:
            result = engine.analyze(photo_bytes)
    """

    def __init__(self, config: EngineConfig | None = None) -> None:
        self.config = config or EngineConfig()
        self._lock = threading.Lock()
        self._closed = False
        self._landmarker: Any = create_face_landmarker(self.config)
        try:
            self._segmenter: Any = create_segmenter(self.config)
        except BaseException:
            # Never leak the first model if the second one fails to load.
            self._close_quietly(self._landmarker)
            raise

    # --- public API ---------------------------------------------------------

    def analyze(
        self,
        image_bytes: bytes,
        quiz: Quiz | None = None,
        debug: bool = False,
    ) -> AnalysisResult:
        """Analyse one photo.

        Expected problems with the photo come back as ``status="rejected"`` with
        a ``reject_reason``; exceptions mean the environment is broken.
        """
        if self._closed:
            raise RuntimeError("Engine is closed.")

        artifacts = DebugArtifacts() if debug else None
        timings: dict[str, float] = {}

        with self._lock:
            return self._run(image_bytes, quiz, artifacts, timings)

    def analyze_garment(self, image_bytes: bytes) -> GarmentResult:
        """Measure the colours of a catalog product photo.

        Uses the same segmenter as the face analysis, so a shop photo of the
        garment on a model is measured on its clothes class; a flat shot falls
        back to the backdrop. See ``garment.py``.
        """
        if self._closed:
            raise RuntimeError("Engine is closed.")

        with self._lock:
            return analyze_garment(
                image_bytes,
                self.config,
                segment=lambda rgb: segment_categories(self._segmenter, rgb),
            )

    def close(self) -> None:
        if self._closed:
            return
        self._closed = True
        self._close_quietly(self._landmarker)
        self._close_quietly(self._segmenter)

    def __enter__(self) -> Engine:
        return self

    def __exit__(
        self,
        exc_type: type[BaseException] | None,
        exc: BaseException | None,
        tb: TracebackType | None,
    ) -> None:
        self.close()

    # --- pipeline -----------------------------------------------------------

    def _run(
        self,
        image_bytes: bytes,
        quiz: Quiz | None,
        artifacts: DebugArtifacts | None,
        timings: dict[str, float],
    ) -> AnalysisResult:
        config = self.config

        try:
            with _timed("load", timings):
                image = load_image(image_bytes, config)
        except ImageInputError as exc:
            logger.info("rejected at load: %s", exc.reject_reason.value)
            return self._rejected(exc.reject_reason, None, artifacts, timings)

        if artifacts is not None:
            artifacts.source_color_space = image.source_color_space
            artifacts.source_format = image.source_format
            artifacts.original_size = (image.original_width, image.original_height)
            artifacts.downscaled = image.downscaled
            artifacts.converted_rgb = image.rgb

        with _timed("detect", timings):
            detection = detect(
                image.rgb, self._landmarker, self._segmenter, config, image.source_scale
            )

        if artifacts is not None:
            artifacts.face_count = detection.face_count
            artifacts.category_mask = detection.category_mask
            artifacts.head_pose_source = detection.head_pose.source
            artifacts.head_pose = {
                "yaw_deg": detection.head_pose.yaw_deg,
                "pitch_deg": detection.head_pose.pitch_deg,
                "roll_deg": detection.head_pose.roll_deg,
                "asymmetry": detection.head_pose.asymmetry,
            }
            if detection.face_count:
                artifacts.landmarks = detection.landmarks
                artifacts.face_bbox = detection.face_bbox

        if detection.reject_reason is not None:
            logger.info("rejected at detect: %s", detection.reject_reason.value)
            return self._rejected(
                detection.reject_reason, image.source_color_space, artifacts, timings
            )

        with _timed("zones", timings):
            zones = build_zones(detection, config)
        if artifacts is not None:
            artifacts.record_zones(zones)

        with _timed("sample", timings):
            measured = sample_zones(image.rgb, zones, config, keep_masks=artifacts is not None)
            samples = measured.samples
            statuses = zone_statuses(zones, measured)
        if artifacts is not None:
            artifacts.sampled_masks = measured.sampled_masks
            if measured.sclera_quality is not None:
                artifacts.sclera_quality = measured.sclera_quality.as_dict()
            artifacts.sclera_sides = [
                {"side": side.side, **side.quality.as_dict()} for side in measured.sclera_sides
            ]

        with _timed("white_balance", timings):
            balanced = apply_white_balance(image.rgb, zones, samples, measured.sclera_sides, config)
            samples = balanced.samples

        if artifacts is not None:
            artifacts.white_balance = balanced.as_dict()
            artifacts.uncorrected_samples = balanced.uncorrected
            if balanced.white_xyz is not None and balanced.applied:
                # Preview only, and only here: nothing downstream measures it.
                artifacts.corrected_rgb = adapt_image(image.rgb, balanced.white_xyz)

        if balanced.reject_reason is not None:
            logger.info("rejected at white_balance: %s", balanced.reject_reason.value)
            return self._rejected(
                balanced.reject_reason,
                image.source_color_space,
                artifacts,
                timings,
                samples=samples,
                zones=statuses,
                white_balance=balanced.info,
            )

        with _timed("quality", timings):
            verdict = quality_gate(measured, balanced, config)
        if artifacts is not None:
            artifacts.quality = verdict.as_dict()
            artifacts.confidence_factor = round(
                balanced.confidence_factor * verdict.confidence_factor, 4
            )
        if verdict.reject_reason is not None:
            logger.info("rejected at quality: %s", verdict.reject_reason.value)
            return self._rejected(
                verdict.reject_reason,
                image.source_color_space,
                artifacts,
                timings,
                samples=samples,
                zones=statuses,
                white_balance=balanced.info,
            )

        # Everything the photograph itself cost us, in one number, so the
        # classifier has a single place to read it from.
        quality_factor = balanced.confidence_factor * verdict.confidence_factor

        with _timed("features", timings):
            features = compute_features(samples, quiz, config, unusable=measured.overexposed)

        with _timed("classify", timings):
            classification = classify(features, quiz, config, quality_factor=quality_factor)
        if artifacts is not None:
            artifacts.classifier = classification.debug

        logger.info("analysed ok in %.1f ms", sum(timings.values()))
        result = AnalysisResult(
            status=Status.OK,
            reject_reason=None,
            source_color_space=image.source_color_space,
            samples=samples,
            zones=statuses,
            white_balance=balanced.info,
            axes=features.axes,
            contrast=features.contrast,
            seasons=classification.seasons,
            confidence=classification.confidence,
            classifier=classification.info,
            algo_version=__version__,
        )
        return result.attach_diagnostics(artifacts, timings)

    def _rejected(
        self,
        reason: RejectReason,
        source_color_space: str | None,
        artifacts: DebugArtifacts | None,
        timings: dict[str, float],
        samples: Samples | None = None,
        zones: dict[str, ZoneStatus] | None = None,
        white_balance: WhiteBalance | None = None,
    ) -> AnalysisResult:
        """A rejection still carries whatever was measured before the gate -
        that is what makes the debug report useful on the photos that fail.

        A photo rejected at ``load`` never reaches white balance, but the
        contract object still has to describe this engine: ``target_calibrated``
        is a property of the configuration, not of the photograph, and a
        consumer reading it off a rejection must get the same answer as off a
        success. ``white_balance_info`` is the one place that decides it.
        """
        result = AnalysisResult(
            status=Status.REJECTED,
            reject_reason=reason,
            source_color_space=source_color_space,
            samples=samples or Samples(),
            zones=zones or {},
            white_balance=white_balance or white_balance_info(self.config),
            algo_version=__version__,
        )
        return result.attach_diagnostics(artifacts, timings)

    @staticmethod
    def _close_quietly(model: Any) -> None:
        try:
            model.close()
        except Exception:  # pragma: no cover - MediaPipe teardown is best-effort
            logger.debug("model close failed", exc_info=True)


@contextmanager
def _timed(step: str, timings: dict[str, float]) -> Iterator[None]:
    start = time.perf_counter()
    try:
        yield
    finally:
        timings[step] = round((time.perf_counter() - start) * 1000.0, 2)
