"""Seasonal colour analysis, wrapping the ``color_engine`` library.

The engine is a library, not a service — it has no HTTP layer and never will.
This module is the only place that knows that, and it does three things:

1. builds one :class:`color_engine.Engine` per process at startup, so the ~20 MB
   of MediaPipe weights are loaded once and not on the first request;
2. treats the whole thing as OPTIONAL. The package and its models may simply
   not be installed, and a deployment that only wants pose detection must
   still start: ``load_analyzer`` reports why it could not be built instead
   of raising, and ``/color-analysis`` answers 503 with that reason;
3. serialises ``analyze`` behind a lock, because MediaPipe Tasks objects are not
   thread-safe and ``/color-analysis`` is a sync endpoint, so Starlette runs it
   in the threadpool where two requests really can arrive at once.

**One engine + a lock, not one engine per thread.** Each engine holds two
MediaPipe graphs and their arenas; the threadpool is 40 threads wide by default,
and this service is explicitly CPU-only on a small box. Per-thread engines would
multiply that memory by the pool width for no throughput at all, because the work
is CPU-bound and there are only a handful of cores — the second thread would be
waiting on a core rather than on the lock. Concurrency of one keeps memory flat
and latency predictable, which is the same trade-off ``PoseDetector`` already
makes for the same reason.

Privacy: image bytes live in memory for the length of the call. Nothing here
writes to disk, and nothing logs a pixel — only status, reject reason and
timings.
"""
from __future__ import annotations

import logging
import threading
from pathlib import Path
from typing import TYPE_CHECKING, Any, Optional

if TYPE_CHECKING:  # pragma: no cover - annotations only
    from color_engine import AnalysisResult, EngineConfig, GarmentResult, Quiz

logger = logging.getLogger("pose-service")

# The package is an OPTIONAL dependency: a deployment that only wants pose
# detection installs neither it nor its models. Every use of it below is
# therefore imported inside the function that needs it - a module-level
# import would take this whole service down on an environment that chose
# not to install it, which is exactly what must not happen.


# Why /color-analysis is unavailable, in words a caller can act on. The
# reason goes into the 503 body and onto /health, so an operator can tell
# "nobody asked for it" apart from "it was asked for and could not load".
DISABLED = (
    "Colour analysis is switched off on this service. "
    "Set COLOR_ENGINE_ENABLED=true to turn it on."
)
NOT_INSTALLED = "The color-engine package is not installed in this environment."


class ColorModelsMissing(RuntimeError):
    """The color-engine model artifacts are not where the configuration says."""


class ColorAnalyzer:
    """A process-wide engine with a lock in front of it."""

    def __init__(self, config: "EngineConfig") -> None:
        from color_engine import Engine

        self._config = config
        self._lock = threading.Lock()
        self._engine = Engine(config)

    @property
    def config(self) -> "EngineConfig":
        return self._config

    def analyze(
        self,
        image_bytes: bytes,
        quiz: Optional["Quiz"] = None,
        debug: bool = False,
    ) -> tuple["AnalysisResult", dict[str, bytes]]:
        """Analyse one photo, and render the debug views when asked.

        Rendering happens inside the lock as well: it reads the debug artifacts
        the same ``analyze`` call produced, and the engine reuses them.
        """
        from color_engine import render_debug_images

        with self._lock:
            result = self._engine.analyze(image_bytes, quiz=quiz, debug=debug)
            images = render_debug_images(result, result.debug) if debug else {}
        return result, images

    def analyze_garment(self, image_bytes: bytes) -> "GarmentResult":
        """Measure the colours of one catalog product photo."""
        with self._lock:
            return self._engine.analyze_garment(image_bytes)

    def close(self) -> None:
        with self._lock:
            self._engine.close()


def build_analyzer(models_dir: Optional[Path] = None) -> ColorAnalyzer:
    """Construct the analyzer, checking the models are actually there.

    ``EngineConfig`` reads the rest of its settings straight from the
    environment (every field is overridable with the ``COLOR_ENGINE_`` prefix),
    which is why ``COLOR_ENGINE_WHITE_BALANCE_ENABLED`` needs no wiring here:
    ``app.config`` has already loaded this service's ``.env`` into the process
    environment by the time this runs.

    :raises ColorModelsMissing: when either artifact is absent.
    :raises ImportError: when the optional package is not installed at all.
    """
    from color_engine import EngineConfig

    config = EngineConfig(models_dir=models_dir) if models_dir else EngineConfig()

    missing = [
        str(path)
        for path in (config.face_landmarker_path(), config.segmenter_path())
        if not path.exists() or path.stat().st_size == 0
    ]
    if missing:
        raise ColorModelsMissing(
            "color-engine models not found: "
            + ", ".join(missing)
            + ". Set COLOR_ENGINE_MODELS_DIR, or run "
            "`python scripts/fetch_models.py` in services/color-engine."
        )

    return ColorAnalyzer(config)


def load_analyzer(
    enabled: bool, models_dir: Optional[Path] = None
) -> tuple[Optional["ColorAnalyzer"], Optional[str]]:
    """Build the analyzer if it is wanted and possible; otherwise say why not.

    Returns ``(analyzer, None)`` on success and ``(None, reason)`` otherwise.
    It never raises, and that is the point: the colour engine is an optional
    extra, and pose detection - the reason this service exists - must not be
    taken down by a missing model set nobody asked for.

    The reason travels to /health and into the 503 body, so an operator can tell
    "switched off" from "switched on and broken" without reading the source.
    """
    if not enabled:
        return None, DISABLED

    try:
        return build_analyzer(models_dir), None
    except ColorModelsMissing as exc:
        return None, str(exc)
    except ImportError:
        return None, NOT_INSTALLED
    except Exception as exc:  # noqa: BLE001 - whatever broke, pose must still serve
        return None, f"The colour engine failed to load: {exc.__class__.__name__}."


def log_outcome(result: "AnalysisResult") -> None:
    """Log what happened. Status, reason and timings — never a pixel."""
    logger.info(
        "[color] %s%s in %.0f ms",
        result.status.value,
        f" ({result.reject_reason.value})" if result.reject_reason else "",
        sum(result.timings_ms.values()),
    )


def parse_quiz(raw: Optional[str]) -> Optional["Quiz"]:
    """Parse the optional quiz JSON. Returns None for an absent or empty field.

    :raises ValueError: on malformed JSON or an unknown answer — the caller turns
        that into a 400, because it is a broken request, not a bad photograph.
    """
    from color_engine import Quiz

    if raw is None or raw.strip() == "":
        return None
    return Quiz.model_validate_json(raw)


def result_payload(result: "AnalysisResult") -> dict[str, Any]:
    """The engine's own contract, unchanged. We do not reshape it."""
    return result.to_dict()


def replay_zone_rules(samples: dict[str, Any]) -> dict[str, Any]:
    """Re-decide stored measurements against the thresholds as they stand now.

    Needs the PACKAGE but not the MODELS. Nothing here opens a photograph: it is
    arithmetic over numbers that were measured weeks ago, and it must keep
    working on a box where the MediaPipe artifacts were never installed - which
    is most of them, since this is a tool for reviewing thresholds rather than
    for serving requests.

    The rules themselves stay in the engine, where the thresholds live. A second
    implementation of them on the caller's side would drift, and a rule-checker
    that disagrees with the engine tells you about itself and nothing else.

    :raises ValueError: when ``samples`` is not the engine's ``Samples`` shape -
        the caller turns that into a 400, because it is a broken request.
    """
    from color_engine import EngineConfig, replay_rules
    from color_engine.models import Samples

    parsed = Samples.model_validate(samples)
    config = EngineConfig()

    return {
        "thresholds": {
            name: getattr(config, name)
            for name in (
                "blown_lightness_min",
                "blown_chroma_max",
                "sclera_min_l_floor",
                "sclera_over_iris_min_l",
                "sclera_under_skin_max_l",
                "sclera_socket_light_ratio",
                "sclera_chroma_ratio",
                "sclera_chroma_floor",
                "sclera_eye_agreement_max_dab",
            )
        },
        "zones": {
            zone: replay.as_dict() for zone, replay in replay_rules(parsed, config).items()
        },
    }
