"""Finding the face and the body-part segmentation.

Two MediaPipe Tasks models are used, both in IMAGE running mode:

* **FaceLandmarker** - 478 landmarks (the 468 face-mesh points plus 2x5 iris
  points) and, optionally, a facial transformation matrix we read head pose off.
* **ImageSegmenter** with ``selfie_multiclass_256x256`` - a per-pixel category
  mask that tells hair from face skin from background.

Neither MediaPipe object is thread-safe. They are created here but owned by
``Engine``, which serialises access; see the note in ``engine.py``.
"""

from __future__ import annotations

import math
from dataclasses import dataclass
from enum import IntEnum
from pathlib import Path
from typing import Any

import cv2
import numpy as np
from numpy.typing import NDArray

from .arrays import FloatArray, ImageArray, MaskArray, PointArray
from .config import EngineConfig
from .errors import ModelFileNotFound
from .models import RejectReason

# Number of landmarks the face_landmarker.task model emits: 468 mesh + 10 iris.
LANDMARK_COUNT = 478


class SegmentClass(IntEnum):
    """Categories of ``selfie_multiclass_256x256``.

    Order per the model card
    (https://ai.google.dev/edge/mediapipe/solutions/vision/image_segmenter):
    0 background, 1 hair, 2 body-skin, 3 face-skin, 4 clothes, 5 others
    (accessories such as glasses frames and jewellery).
    """

    BACKGROUND = 0
    HAIR = 1
    BODY_SKIN = 2
    FACE_SKIN = 3
    CLOTHES = 4
    OTHERS = 5


@dataclass(frozen=True)
class HeadPose:
    """Approximate head orientation in degrees, plus the raw asymmetry measure.

    Angles come from the facial transformation matrix when MediaPipe provides
    one. They are good enough for a frontality gate, not for metrology.
    """

    yaw_deg: float | None
    pitch_deg: float | None
    roll_deg: float | None
    asymmetry: float
    source: str  # "matrix" or "landmarks"


@dataclass(frozen=True)
class Detection:
    """Everything the later steps need to know about where the face is."""

    face_count: int
    landmarks: PointArray  # (478, 2), pixel coordinates
    landmarks_z: NDArray[np.float32]  # (478,), MediaPipe depth units
    face_bbox: tuple[float, float, float, float]  # x, y, w, h in pixels
    face_width: float
    head_pose: HeadPose
    category_mask: MaskArray  # (H, W) of SegmentClass values
    reject_reason: RejectReason | None = None

    source_scale: float = 1.0
    """Original pixels per analysed pixel, >= 1. Everything here is measured on
    the image as decoded and possibly downscaled; the absolute size floors are
    about how much the *camera* captured, so they multiply back through this."""

    @property
    def ok(self) -> bool:
        return self.reject_reason is None

    @property
    def face_width_source(self) -> float:
        """Face width in the original image's pixels."""
        return self.face_width * self.source_scale


def create_face_landmarker(config: EngineConfig) -> Any:
    """Build the FaceLandmarker. Raises ``ModelFileNotFound`` with a fix hint."""
    from mediapipe.tasks import python as mp_python
    from mediapipe.tasks.python import vision

    path = config.face_landmarker_path()
    _require(path)
    options = vision.FaceLandmarkerOptions(
        base_options=mp_python.BaseOptions(model_asset_path=str(path)),
        running_mode=vision.RunningMode.IMAGE,
        # 2, not 1: we must be able to say "there is more than one face here"
        # rather than silently analysing whichever one MediaPipe liked best.
        num_faces=config.max_faces,
        min_face_detection_confidence=config.min_face_detection_confidence,
        min_face_presence_confidence=config.min_face_presence_confidence,
        min_tracking_confidence=config.min_face_tracking_confidence,
        output_face_blendshapes=False,
        output_facial_transformation_matrixes=config.use_transformation_matrix,
    )
    return vision.FaceLandmarker.create_from_options(options)


def create_segmenter(config: EngineConfig) -> Any:
    """Build the multiclass ImageSegmenter."""
    from mediapipe.tasks import python as mp_python
    from mediapipe.tasks.python import vision

    path = config.segmenter_path()
    _require(path)
    options = vision.ImageSegmenterOptions(
        base_options=mp_python.BaseOptions(model_asset_path=str(path)),
        running_mode=vision.RunningMode.IMAGE,
        output_category_mask=True,
        output_confidence_masks=False,
    )
    return vision.ImageSegmenter.create_from_options(options)


def _require(path: Path) -> None:
    if not path.exists() or path.stat().st_size == 0:
        raise ModelFileNotFound(path)


def to_mp_image(rgb: ImageArray) -> Any:
    """Wrap an RGB uint8 array as a MediaPipe Image (no copy beyond contiguity)."""
    import mediapipe as mp

    return mp.Image(image_format=mp.ImageFormat.SRGB, data=np.ascontiguousarray(rgb))


def detect(
    rgb: ImageArray,
    landmarker: Any,
    segmenter: Any,
    config: EngineConfig,
    source_scale: float = 1.0,
) -> Detection:
    """Locate the face and segment the image.

    Returns a ``Detection`` whose ``reject_reason`` is set when the photo cannot
    carry an analysis. A rejected detection still carries whatever was measured,
    which is what makes the debug report useful on failures.

    ``source_scale`` is original pixels per analysed pixel, so the absolute size
    floors can be applied to what the camera captured rather than to what
    survived downscaling.
    """
    height, width = rgb.shape[0], rgb.shape[1]
    mp_image = to_mp_image(rgb)

    face_result = landmarker.detect(mp_image)
    faces = list(face_result.face_landmarks or [])

    category_mask = _segment(segmenter, mp_image, width, height)

    if not faces:
        return _empty_detection(category_mask, RejectReason.NO_FACE)

    # Landmarks of the largest face - with num_faces=2 the "largest" pick only
    # matters for the debug view, since >1 face is rejected anyway.
    landmark_sets = [_landmarks_to_pixels(f, width, height) for f in faces]
    areas = [_bbox(pts)[2] * _bbox(pts)[3] for pts in landmark_sets]
    best = int(np.argmax(areas))
    points = landmark_sets[best]
    z = np.asarray([lm.z for lm in faces[best]], dtype=np.float32)
    bbox = _bbox(points)

    matrices = list(getattr(face_result, "facial_transformation_matrixes", None) or [])
    pose = _head_pose(points, matrices[best] if best < len(matrices) else None)

    reason: RejectReason | None = None
    if len(faces) > 1:
        reason = RejectReason.MULTIPLE_FACES
    elif too_small(bbox, (width, height), source_scale, config):
        reason = RejectReason.FACE_TOO_SMALL
    elif _not_frontal(pose, config):
        reason = RejectReason.FACE_NOT_FRONTAL

    return Detection(
        face_count=len(faces),
        landmarks=points,
        landmarks_z=z,
        face_bbox=bbox,
        face_width=bbox[2],
        head_pose=pose,
        category_mask=category_mask,
        reject_reason=reason,
        source_scale=source_scale,
    )


def too_small(
    bbox: tuple[float, float, float, float],
    frame: tuple[int, int],
    source_scale: float,
    config: EngineConfig,
) -> bool:
    """Is the face too small to measure - relatively, or absolutely?

    Two questions, and both have to be asked. The *ratio* catches a face lost in
    a wide shot. The *absolute* width catches a small photograph of a large
    face: a 640x480 frame filled edge to edge passes any ratio you like while
    carrying a fraction of the detail of the same composition shot on a phone,
    and what the colour measurement depends on is how many sensor pixels landed
    on the skin.

    The absolute test is applied at source resolution, because downscaling to
    ``max_side`` is our own doing and says nothing about the camera.
    """
    width, height = frame
    if max(bbox[2] / width, bbox[3] / height) < config.min_face_frame_ratio:
        return True
    return bbox[2] * source_scale < config.min_face_width_px


def segment_categories(segmenter: Any, rgb: ImageArray) -> MaskArray:
    """The segmenter's category mask for a whole image, at its resolution."""
    height, width = rgb.shape[:2]
    return _segment(segmenter, to_mp_image(rgb), width, height)


def _segment(segmenter: Any, mp_image: Any, width: int, height: int) -> MaskArray:
    result = segmenter.segment(mp_image)
    mask = np.asarray(result.category_mask.numpy_view(), dtype=np.uint8)
    if mask.shape[:2] != (height, width):
        # The task normally returns the mask at input resolution; resize with
        # nearest so class ids are never interpolated into nonsense.
        mask = np.asarray(
            cv2.resize(mask, (width, height), interpolation=cv2.INTER_NEAREST), dtype=np.uint8
        )
    return mask


def _empty_detection(category_mask: MaskArray, reason: RejectReason) -> Detection:
    return Detection(
        face_count=0,
        landmarks=np.zeros((0, 2), dtype=np.float32),
        landmarks_z=np.zeros((0,), dtype=np.float32),
        face_bbox=(0.0, 0.0, 0.0, 0.0),
        face_width=0.0,
        head_pose=HeadPose(None, None, None, 0.0, "landmarks"),
        category_mask=category_mask,
        reject_reason=reason,
    )


def _landmarks_to_pixels(face: Any, width: int, height: int) -> PointArray:
    return np.asarray([(lm.x * width, lm.y * height) for lm in face], dtype=np.float32)


def _bbox(points: PointArray) -> tuple[float, float, float, float]:
    x0, y0 = points.min(axis=0)
    x1, y1 = points.max(axis=0)
    return (float(x0), float(y0), float(x1 - x0), float(y1 - y0))


# Face-oval extremes and the nose tip, used for the landmark pose fallback.
NOSE_TIP = 1
FACE_OVAL_RIGHT = 234  # subject's right, image left on a frontal photo
FACE_OVAL_LEFT = 454
RIGHT_EYE_OUTER = 33
LEFT_EYE_OUTER = 263


def _head_pose(points: PointArray, matrix: Any) -> HeadPose:
    asymmetry = _yaw_asymmetry(points)
    if matrix is not None:
        angles = _euler_from_matrix(np.asarray(matrix, dtype=np.float64))
        if angles is not None:
            pitch, yaw, roll = angles
            return HeadPose(yaw, pitch, roll, asymmetry, "matrix")

    # Fallback: roll is measurable off the eye line, yaw only as asymmetry.
    dx = float(points[LEFT_EYE_OUTER][0] - points[RIGHT_EYE_OUTER][0])
    dy = float(points[LEFT_EYE_OUTER][1] - points[RIGHT_EYE_OUTER][1])
    roll = math.degrees(math.atan2(dy, dx)) if (dx or dy) else 0.0
    return HeadPose(None, None, roll, asymmetry, "landmarks")


def _yaw_asymmetry(points: PointArray) -> float:
    """0 when the nose sits midway between the face sides, 1 at full profile."""
    if len(points) <= max(FACE_OVAL_LEFT, FACE_OVAL_RIGHT):  # pragma: no cover - defensive
        return 0.0
    nose_x = float(points[NOSE_TIP][0])
    left = abs(float(points[FACE_OVAL_LEFT][0]) - nose_x)
    right = abs(nose_x - float(points[FACE_OVAL_RIGHT][0]))
    total = left + right
    if total <= 0:  # pragma: no cover - degenerate
        return 0.0
    return abs(left - right) / total


def _euler_from_matrix(matrix: FloatArray) -> tuple[float, float, float] | None:
    """Decompose MediaPipe's 4x4 face transform into (pitch, yaw, roll) degrees.

    The rotation block may carry scale, so columns are normalised first. The
    decomposition assumes R = Rz(roll) @ Ry(yaw) @ Rx(pitch), which is the usual
    convention for head pose and is accurate enough for a frontality threshold.
    """
    if matrix.shape != (4, 4):  # pragma: no cover - defensive
        return None
    rotation = matrix[:3, :3].astype(np.float64)
    scales = np.linalg.norm(rotation, axis=0)
    if not np.all(scales > 1e-9):  # pragma: no cover - degenerate
        return None
    rotation = rotation / scales

    sy = math.hypot(rotation[0, 0], rotation[1, 0])
    if sy < 1e-6:  # pragma: no cover - gimbal lock, not reachable for a face
        pitch = math.atan2(-rotation[1, 2], rotation[1, 1])
        yaw = math.atan2(-rotation[2, 0], sy)
        roll = 0.0
    else:
        pitch = math.atan2(rotation[2, 1], rotation[2, 2])
        yaw = math.atan2(-rotation[2, 0], sy)
        roll = math.atan2(rotation[1, 0], rotation[0, 0])
    return (math.degrees(pitch), math.degrees(yaw), math.degrees(roll))


def _not_frontal(pose: HeadPose, config: EngineConfig) -> bool:
    if pose.source == "matrix":
        return (
            abs(pose.yaw_deg or 0.0) > config.max_yaw_deg
            or abs(pose.pitch_deg or 0.0) > config.max_pitch_deg
            or abs(pose.roll_deg or 0.0) > config.max_roll_deg
        )
    return pose.asymmetry > config.max_face_asymmetry
