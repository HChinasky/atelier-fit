"""FastAPI application: GET /health and POST /pose.

Privacy: uploaded image bytes are decoded in memory, run through the detector,
and discarded when the request returns. Nothing is written to disk and no image
content is logged.
"""
from __future__ import annotations

import base64
import logging
from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI, File, Form, HTTPException, UploadFile, status
from fastapi.responses import JSONResponse, Response

from . import enhance
from .auth import require_token
from .color import (
    DISABLED,
    ColorAnalyzer,
    load_analyzer,
    log_outcome,
    parse_quiz,
    replay_zone_rules,
    result_payload,
)
from .config import get_settings
from .detector import MODEL_ID, PoseDetector
from .imaging import ImageDecodeError, decode_to_rgb
from .schemas import (
    BoundingBox,
    ColorAnalysisResponse,
    ColorEngineHealth,
    GarmentColourResponse,
    HealthResponse,
    ImageDimensions,
    Landmark,
    Person,
    PoseResponse,
    RuleCheckRequest,
    RuleCheckResponse,
)

logger = logging.getLogger("pose-service")

# Holds the single shared detector and colour analyzer for the process lifetime.
_state: dict = {"detector": None, "color": None, "color_reason": None}


@asynccontextmanager
async def lifespan(app: FastAPI):
    settings = get_settings()
    logger.info("Loading pose model from %s", settings.model_path)
    _state["detector"] = PoseDetector(
        model_path=settings.model_path,
        max_persons=settings.max_persons,
        min_detection_confidence=settings.min_pose_detection_confidence,
        min_presence_confidence=settings.min_pose_presence_confidence,
        bbox_min_confidence=settings.bbox_min_confidence,
    )
    logger.info("Pose model loaded.")

    # Optional, and it must stay that way: pose detection is why this service
    # exists, and a colour model set nobody asked for must never stop it
    # starting. Decided once here rather than per request, so the first
    # /color-analysis after a deploy does not pay for a model load and an
    # operator learns about a misconfiguration from the startup log.
    _state["color"], _state["color_reason"] = load_analyzer(
        settings.color_enabled, settings.color_models_dir
    )
    if _state["color"] is not None:
        logger.info("Colour engine loaded.")
    else:
        logger.warning("[color] /color-analysis unavailable: %s", _state["color_reason"])

    try:
        yield
    finally:
        detector = _state.get("detector")
        if detector is not None:
            detector.close()
        _state["detector"] = None
        analyzer = _state.get("color")
        if analyzer is not None:
            analyzer.close()
        _state["color"] = None
        _state["color_reason"] = None


app = FastAPI(
    title="AI Fitting Room — Pose Detector",
    description=(
        "Stateless CPU pose-detection microservice. Pure detector: returns facts "
        "about an image (person count, landmarks, bounding box, dimensions) and "
        "makes no judgement about photo suitability."
    ),
    version="1.0.0",
    lifespan=lifespan,
)


def get_detector() -> PoseDetector:
    detector = _state.get("detector")
    if detector is None:  # pragma: no cover - only if startup failed
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="Pose model is not loaded.",
        )
    return detector


def get_color_analyzer() -> ColorAnalyzer:
    """The analyzer, or a 503 saying why there isn't one.

    503 and not 501: the endpoint exists in this build, it just cannot serve
    a request in this deployment. The body carries the reason verbatim so an
    operator reading a log line can tell a switch that is off from a model
    set that is missing, without opening the source.
    """
    analyzer = _state.get("color")
    if analyzer is None:
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail=_state.get("color_reason") or DISABLED,
        )
    return analyzer


@app.get("/health", response_model=HealthResponse, tags=["meta"])
async def health() -> HealthResponse:
    """Is this service able to do its job?

    ``status`` answers for POSE DETECTION only, because that is what this
    service is for. The colour engine is an optional extra and gets its own
    field: a deployment that never wanted it is healthy, and reporting it as
    degraded would train everyone to ignore the field that matters.
    """
    loaded = _state.get("detector") is not None
    analyzer = _state.get("color")
    return HealthResponse(
        status="ok" if loaded else "degraded",
        model_loaded=loaded,
        model=MODEL_ID,
        color_engine=ColorEngineHealth(
            available=analyzer is not None,
            detail=_state.get("color_reason"),
        ),
    )


@app.post(
    "/pose",
    response_model=PoseResponse,
    tags=["detection"],
    dependencies=[Depends(require_token)],
    responses={
        400: {"description": "Missing, empty, or undecodable image."},
        401: {"description": "Missing or invalid API token."},
        413: {"description": "Uploaded image exceeds the size limit."},
    },
)
async def pose(image: UploadFile = File(..., description="Image file (multipart).")):
    settings = get_settings()
    raw = await image.read()

    if len(raw) > settings.max_upload_bytes:
        raise HTTPException(
            status_code=status.HTTP_413_REQUEST_ENTITY_TOO_LARGE,
            detail=f"Image exceeds the {settings.max_upload_bytes} byte limit.",
        )

    try:
        rgb = decode_to_rgb(raw)
    except ImageDecodeError as exc:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST, detail=str(exc)
        ) from exc
    finally:
        # Drop the raw bytes promptly; never persisted, never logged.
        del raw

    detector = get_detector()
    result = detector.detect(rgb)

    main_person = None
    if result.main_person is not None:
        mp_ = result.main_person
        bx, by, bw, bh = mp_.bbox
        main_person = Person(
            bounding_box=BoundingBox(x=bx, y=by, width=bw, height=bh),
            landmarks=[
                Landmark(
                    name=lm.name,
                    x=lm.x,
                    y=lm.y,
                    x_norm=lm.x_norm,
                    y_norm=lm.y_norm,
                    z=lm.z,
                    confidence=lm.confidence,
                )
                for lm in mp_.landmarks
            ],
        )

    return PoseResponse(
        person_count=result.person_count,
        image_dimensions=ImageDimensions(width=result.width, height=result.height),
        main_person=main_person,
        bbox_confidence_threshold=settings.bbox_min_confidence,
        model=MODEL_ID,
    )


@app.post(
    "/preprocess-image",
    tags=["enhancement"],
    dependencies=[Depends(require_token)],
    responses={
        200: {
            "content": {"image/jpeg": {}, "image/png": {}, "image/webp": {}},
            "description": (
                "Enhanced image bytes (SAME dimensions as the input). Headers "
                "X-Applied-Lighting / X-Applied-Segment report which steps ran."
            ),
        },
        400: {"description": "Missing, empty, or undecodable image."},
        401: {"description": "Missing or invalid API token."},
        413: {"description": "Uploaded image exceeds the size limit."},
    },
)
async def preprocess_image(
    image: UploadFile = File(..., description="Image file (multipart)."),
    lighting: bool = Form(False, description="Apply lighting normalization."),
    segment: bool = Form(False, description="Segment the person onto a neutral bg."),
    format: str = Form("jpeg", description="Output format: jpeg | png | webp."),
):
    """Lighting normalization + person segmentation for the input (person) photo.

    The two steps are independently toggled by the caller; dimensions are never
    changed (the backend's crop step depends on that). Each step is best-effort:
    a step that fails is skipped (reported via the X-Applied-* headers) rather
    than failing the whole request, so the backend always gets a usable image and
    its own fail-open policy only has to handle a hard transport/HTTP error.
    """
    settings = get_settings()
    raw = await image.read()

    if len(raw) > settings.max_upload_bytes:
        raise HTTPException(
            status_code=status.HTTP_413_REQUEST_ENTITY_TOO_LARGE,
            detail=f"Image exceeds the {settings.max_upload_bytes} byte limit.",
        )

    try:
        rgb = decode_to_rgb(raw)
    except ImageDecodeError as exc:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST, detail=str(exc)
        ) from exc
    finally:
        del raw

    applied_lighting = False
    applied_segment = False

    if lighting:
        try:
            rgb = enhance.normalize_lighting(
                rgb,
                clahe_clip=settings.clahe_clip,
                clahe_tile=settings.clahe_tile,
                white_balance=settings.white_balance,
                gamma_correct=settings.gamma_correct,
                gamma_target=settings.gamma_target,
            )
            applied_lighting = True
        except Exception:  # noqa: BLE001 — best-effort, never leak details/pixels
            logger.warning("[enhance] lighting normalization failed; skipping")

    if segment:
        try:
            rgb = enhance.segment_on_background(
                rgb,
                model_name=settings.segment_model,
                bg_color=settings.bg_color,
                keep_loaded=settings.segment_keep_loaded,
            )
            applied_segment = True
        except Exception:  # noqa: BLE001 — best-effort, return the lit image instead
            logger.warning("[enhance] segmentation failed; skipping")

    out_bytes, media_type = enhance.encode(rgb, format, settings.output_quality)

    return Response(
        content=out_bytes,
        media_type=media_type,
        headers={
            "X-Applied-Lighting": "true" if applied_lighting else "false",
            "X-Applied-Segment": "true" if applied_segment else "false",
        },
    )


@app.post(
    "/color-analysis",
    response_model=ColorAnalysisResponse,
    tags=["colour"],
    dependencies=[Depends(require_token)],
    responses={
        400: {"description": "Missing/empty image, or a malformed quiz."},
        401: {"description": "Missing or invalid API token."},
        413: {"description": "Uploaded image exceeds the engine's size limit."},
    },
)
def color_analysis(
    image: UploadFile = File(..., description="Image file (multipart), ORIGINAL bytes."),
    quiz: str = Form("", description="Quiz answers as a JSON object. Optional."),
    debug: bool = Form(False, description="Also return the rendered debug views."),
):
    """Seasonal colour analysis of a face photo.

    **The bytes arrive here untouched.** None of the ``/preprocess-image``
    lighting normalisation is applied, in any form: CLAHE, grey-world white
    balance and gamma correction all rewrite exactly the colours this endpoint
    exists to measure. The engine does its own colour management from the
    original file — ICC profile included — and anything done to the pixels
    before it sees them is measurement error.

    Declared ``def`` rather than ``async def`` on purpose: the analysis is a
    second or so of CPU-bound work, and on the event loop it would stall every
    other request including /health. In the threadpool it does not, at the cost
    of needing the lock in ``app.color`` — MediaPipe objects are not thread-safe.

    A photo the engine refuses is a **200** carrying ``status="rejected"`` and a
    reason: that is a fact about the photograph, not an HTTP error. 4xx is for a
    broken request, 5xx for a broken environment.
    """
    analyzer = get_color_analyzer()

    # The engine's own limit, not this service's: the caller should get the same
    # answer whether the engine is reached over HTTP or imported directly.
    limit = analyzer.config.max_input_bytes
    raw = image.file.read()
    if len(raw) > limit:
        raise HTTPException(
            status_code=status.HTTP_413_REQUEST_ENTITY_TOO_LARGE,
            detail=f"Image exceeds the {limit} byte limit.",
        )
    if not raw:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST, detail="Empty image payload."
        )

    try:
        answers = parse_quiz(quiz)
    except ValueError as exc:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"Malformed quiz: {exc.__class__.__name__}",
        ) from exc

    try:
        result, images = analyzer.analyze(raw, quiz=answers, debug=debug)
    finally:
        # Drop the bytes promptly; never persisted, never logged.
        del raw

    log_outcome(result)

    return ColorAnalysisResponse(
        result=result_payload(result),
        debug_images=(
            {name: base64.b64encode(payload).decode("ascii") for name, payload in images.items()}
            if debug
            else None
        ),
    )


@app.post(
    "/garment-colour",
    response_model=GarmentColourResponse,
    tags=["colour"],
    dependencies=[Depends(require_token)],
    responses={
        400: {"description": "Missing or empty image."},
        401: {"description": "Missing or invalid API token."},
        413: {"description": "Uploaded image exceeds the engine's size limit."},
        503: {"description": "Colour analysis is switched off in this deployment."},
    },
)
def garment_colour(
    image: UploadFile = File(..., description="Catalog product photo (multipart), ORIGINAL bytes."),
):
    """The colours of a catalog garment, measured on the face's scale.

    The same engine, the same colour management and the same Lab conversion as
    ``/color-analysis`` - a garment and a cheek must be measured alike, or
    "does this colour suit her" means nothing. That is also why it sits behind
    the same switch: a worn shop photo is isolated with the engine's
    segmenter, which is part of what ``COLOR_ENGINE_ENABLED`` loads.

    Whether a colour suits anybody is NOT decided here. The caller compares
    the result with a season's palette, which it owns.

    A photo the engine could not measure (``no_garment``, a garment the colour
    of its backdrop; ``low_resolution``; ``unsupported_format``) is a **200**
    with ``status="rejected"`` - a fact about the photo, not an HTTP error.
    """
    analyzer = get_color_analyzer()

    limit = analyzer.config.max_input_bytes
    raw = image.file.read()
    if len(raw) > limit:
        raise HTTPException(
            status_code=status.HTTP_413_REQUEST_ENTITY_TOO_LARGE,
            detail=f"Image exceeds the {limit} byte limit.",
        )
    if not raw:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST, detail="Empty image payload."
        )

    try:
        result = analyzer.analyze_garment(raw)
    finally:
        del raw

    logger.info(
        "[garment] %s%s",
        result.status.value,
        f" ({result.reject_reason.value})" if result.reject_reason else "",
    )

    return GarmentColourResponse(result=result.model_dump(mode="json"))


@app.post(
    "/color-rule-check",
    response_model=RuleCheckResponse,
    tags=["colour"],
    dependencies=[Depends(require_token)],
    responses={
        400: {"description": "The samples are not the engine's Samples shape."},
        401: {"description": "Missing or invalid API token."},
        503: {"description": "Colour analysis is switched off in this deployment."},
    },
)
def color_rule_check(payload: RuleCheckRequest):
    """What today's thresholds would say about a face measured earlier.

    Read-only, and no photograph is involved: the numbers come in, the rules run
    over them, and the answer goes back. Nothing is stored and nothing is
    re-measured.

    It exists because every threshold the engine judges a zone on is marked
    PRELIMINARY, and the honest way to move one is to see what it would do to
    the faces already collected. The photographs are deleted on a 60-hour clock,
    so "re-run the set" is not available; the stored numbers are all there is.

    **No models, and no switch.** Unlike ``/color-analysis`` this needs the
    package and nothing else: no MediaPipe artifacts, no photograph, no engine
    held in memory. It is deliberately NOT gated on ``COLOR_ENGINE_ENABLED``,
    because that switch exists to keep a deployment from loading 20 MB of
    weights nobody asked for, and there is nothing here for it to protect - the
    request carries its own numbers and the answer is arithmetic over them. A
    threshold review happens on whatever box is to hand, which is rarely the one
    serving photos.

    The rules live in the engine, where the thresholds do. Reimplementing them
    in the caller would give an answer that drifts from the thing it checks.
    """
    try:
        return RuleCheckResponse(**replay_zone_rules(payload.samples))
    except ImportError as exc:  # pragma: no cover - an install without the package
        raise HTTPException(
            status_code=status.HTTP_503_SERVICE_UNAVAILABLE,
            detail="The color-engine package is not installed in this environment.",
        ) from exc
    except ValueError as exc:
        raise HTTPException(
            status_code=status.HTTP_400_BAD_REQUEST,
            detail=f"Malformed samples: {exc.__class__.__name__}",
        ) from exc


@app.exception_handler(ImageDecodeError)
async def _decode_error_handler(_request, exc: ImageDecodeError):  # pragma: no cover
    return JSONResponse(status_code=400, content={"detail": str(exc)})
