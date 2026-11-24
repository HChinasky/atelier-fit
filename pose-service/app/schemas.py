"""Pydantic response models — these define the public API contract."""
from __future__ import annotations

from typing import Any, Dict, List, Optional

from pydantic import BaseModel, Field


class Landmark(BaseModel):
    name: str = Field(..., description="Body point name, e.g. 'left_ankle'.")
    x: float = Field(..., description="X coordinate in source-image pixels.")
    y: float = Field(..., description="Y coordinate in source-image pixels.")
    x_norm: float = Field(..., description="X normalized to [0,1] of image width.")
    y_norm: float = Field(..., description="Y normalized to [0,1] of image height.")
    z: float = Field(
        ...,
        description="Relative depth (MediaPipe units); negative is closer to camera. "
        "Roughly normalized to torso scale. Not pixel-accurate.",
    )
    confidence: float = Field(
        ...,
        ge=0.0,
        le=1.0,
        description="Visibility/presence probability of this landmark in [0,1].",
    )


class BoundingBox(BaseModel):
    x: float = Field(..., description="Left edge in pixels.")
    y: float = Field(..., description="Top edge in pixels.")
    width: float = Field(..., description="Width in pixels.")
    height: float = Field(..., description="Height in pixels.")


class ImageDimensions(BaseModel):
    width: int
    height: int


class Person(BaseModel):
    bounding_box: BoundingBox = Field(
        ...,
        description="Tight box over landmarks whose confidence >= "
        "bbox_confidence_threshold (extrapolated/off-frame points excluded), "
        "clamped to the image.",
    )
    landmarks: List[Landmark]


class PoseResponse(BaseModel):
    person_count: int = Field(..., description="Number of people detected (0..N).")
    image_dimensions: ImageDimensions
    main_person: Optional[Person] = Field(
        None,
        description="The most prominent person (largest confident bounding-box "
        "area), or null when person_count == 0.",
    )
    bbox_confidence_threshold: float = Field(
        ...,
        description="Confidence cutoff used to build bounding boxes. Landmarks "
        "below it are kept in `landmarks` (with their true low confidence) but "
        "do not contribute to the box.",
    )
    model: str = Field(..., description="Identifier of the detection model used.")


class ColorEngineHealth(BaseModel):
    """Whether the optional colour engine can serve a request here.

    Separate from the overall status on purpose: this service exists to
    detect poses, and a deployment that never wanted colour analysis is
    perfectly healthy without it.
    """

    available: bool = Field(
        ..., description="True when POST /color-analysis can be served."
    )
    detail: Optional[str] = Field(
        None,
        description="Why it is unavailable - switched off, package missing, models missing. Null when it is available.",
    )


class HealthResponse(BaseModel):
    status: str = Field(
        ..., description="Health of POSE DETECTION, which is what this service is for."
    )
    model_loaded: bool
    model: str
    color_engine: Optional[ColorEngineHealth] = None


class GarmentColourResponse(BaseModel):
    """What POST /garment-colour returns.

    ``result`` is the colour engine's ``GarmentResult`` exactly as it
    serialises it, like /color-analysis passes its result through: the engine
    owns that shape. A photo it could not measure is a normal 200 with
    ``status="rejected"`` inside.
    """

    result: Dict[str, Any] = Field(
        ...,
        description="The engine's GarmentResult: status, reject_reason, method, coverage, "
        "and colours (CIELAB, hex, share), largest share first.",
    )


class ColorAnalysisResponse(BaseModel):
    """What POST /color-analysis returns.

    ``result`` is the colour engine's own contract, passed through untouched —
    this service does not reshape it, so the schema that governs it is
    ``color_engine/docs/contract.schema.json`` and not this file. Reshaping it
    here would mean two places to change every time the engine grows a field.
    """

    result: Dict[str, Any] = Field(
        ...,
        description="The engine's AnalysisResult exactly as it serialises it. "
        "A rejected photo is a normal 200 with status='rejected' inside.",
    )
    debug_images: Optional[Dict[str, str]] = Field(
        None,
        description="Base64 PNG per view (overlay, zone crops, white-balance "
        "before/after). Present only when the request asked for debug, and a "
        "name is absent when the photo did not produce that view.",
    )


class RuleCheckRequest(BaseModel):
    """What POST /color-rule-check is asked.

    ``samples`` is the engine's own ``Samples`` object, exactly as it was stored
    when the profile was measured. The caller sends back what it was given; it
    does not reshape it and neither does this service.
    """

    samples: Dict[str, Any] = Field(
        ...,
        description="The `samples` section of a stored AnalysisResult, unchanged.",
    )


class RuleCheckResponse(BaseModel):
    """What the thresholds as they stand today would say about those numbers.

    Read-only and stateless: nothing is stored, nothing is re-measured, and no
    photograph is involved - the photograph is long deleted by the time anybody
    asks this. A verdict here is arithmetic over the numbers in the request.

    The rules are the engine's, not this service's. See
    ``color_engine/rules.py`` for which checks can be replayed at all and which
    need pixels that no longer exist; each zone carries its own `blind_spots`.
    """

    thresholds: Dict[str, Any] = Field(
        ...,
        description="The threshold values the replay was run with, so an answer "
        "can be read months later without guessing which build produced it.",
    )
    zones: Dict[str, Any] = Field(
        ...,
        description="Per zone: whether it would pass now, which check decided it, "
        "every check with its value, limit and margin, and what could not be "
        "re-run without the photograph.",
    )


class ErrorResponse(BaseModel):
    error: str
    detail: Optional[str] = None
