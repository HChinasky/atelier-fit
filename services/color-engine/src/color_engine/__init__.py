"""color-engine - seasonal colour analysis from a face photo.

A library, not a service. ``pose-service`` will import it and expose
``POST /color-analysis``; Laravel only ever talks to that HTTP contract.

    from color_engine import Engine, EngineConfig

    with Engine(EngineConfig()) as engine:
        result = engine.analyze(photo_bytes)
        print(result.to_json(indent=2))

One ``Engine`` per process or worker: MediaPipe objects are not thread-safe and
the models are loaded in the constructor.
"""

from __future__ import annotations

from .config import EngineConfig
from .debug import DebugArtifacts, LandmarkLabels
from .engine import Engine, __version__
from .errors import (
    ColorEngineError,
    ConfigurationError,
    FileTooLarge,
    ImageInputError,
    ImageTooSmall,
    ModelFileNotFound,
    UnsupportedFormat,
)
from .garment import (
    GARMENT_ALGO_VERSION,
    GarmentColour,
    GarmentMethod,
    GarmentRejectReason,
    GarmentResult,
    GarmentStatus,
)
from .images import IMAGE_NAMES, debug_images, render_debug_images
from .models import (
    AnalysisResult,
    Axes,
    Clarity,
    Contrast,
    LabSample,
    Metal,
    PreviousHair,
    Quiz,
    RejectReason,
    Samples,
    Season,
    SeasonScore,
    SkinParts,
    Status,
    Tanning,
    Veins,
    WhiteBalance,
    WhiteBalanceMethod,
    contract_schema,
)
from .rules import Check, ZoneReplay, replay_rules

__all__ = [
    "IMAGE_NAMES",
    "AnalysisResult",
    "Axes",
    "Check",
    "Clarity",
    "ColorEngineError",
    "ConfigurationError",
    "Contrast",
    "DebugArtifacts",
    "Engine",
    "EngineConfig",
    "FileTooLarge",
    "GARMENT_ALGO_VERSION",
    "GarmentColour",
    "GarmentMethod",
    "GarmentRejectReason",
    "GarmentResult",
    "GarmentStatus",
    "ImageInputError",
    "ImageTooSmall",
    "LabSample",
    "LandmarkLabels",
    "Metal",
    "ModelFileNotFound",
    "Quiz",
    "RejectReason",
    "Samples",
    "Season",
    "SeasonScore",
    "SkinParts",
    "Status",
    "Tanning",
    "UnsupportedFormat",
    "Veins",
    "WhiteBalance",
    "WhiteBalanceMethod",
    "ZoneReplay",
    "__version__",
    "contract_schema",
    "debug_images",
    "render_debug_images",
    "replay_rules",
]
