"""MediaPipe Pose Landmarker wrapper.

Loaded once at process start and reused. MediaPipe Tasks objects are not
guaranteed thread-safe for concurrent ``detect`` calls, and FastAPI runs sync
endpoints in a thread pool, so detection is serialized behind a lock. For a
CPU-bound local service that is the correct, predictable trade-off.
"""
from __future__ import annotations

import threading
from pathlib import Path
from typing import List, Optional

import numpy as np

# BlazePose 33-landmark ordering. Index == MediaPipe landmark index.
LANDMARK_NAMES: List[str] = [
    "nose",
    "left_eye_inner",
    "left_eye",
    "left_eye_outer",
    "right_eye_inner",
    "right_eye",
    "right_eye_outer",
    "left_ear",
    "right_ear",
    "mouth_left",
    "mouth_right",
    "left_shoulder",
    "right_shoulder",
    "left_elbow",
    "right_elbow",
    "left_wrist",
    "right_wrist",
    "left_pinky",
    "right_pinky",
    "left_index",
    "right_index",
    "left_thumb",
    "right_thumb",
    "left_hip",
    "right_hip",
    "left_knee",
    "right_knee",
    "left_ankle",
    "right_ankle",
    "left_heel",
    "right_heel",
    "left_foot_index",
    "right_foot_index",
]

MODEL_ID = "mediapipe_pose_landmarker_full_v1"


class DetectedLandmark:
    __slots__ = ("name", "x", "y", "x_norm", "y_norm", "z", "confidence")

    def __init__(self, name, x, y, x_norm, y_norm, z, confidence):
        self.name = name
        self.x = x
        self.y = y
        self.x_norm = x_norm
        self.y_norm = y_norm
        self.z = z
        self.confidence = confidence


class DetectedPerson:
    __slots__ = ("landmarks", "bbox")

    def __init__(self, landmarks: List[DetectedLandmark], bbox):
        self.landmarks = landmarks
        self.bbox = bbox  # (x, y, width, height) in pixels


class DetectionResult:
    __slots__ = ("person_count", "main_person", "width", "height")

    def __init__(self, person_count, main_person, width, height):
        self.person_count = person_count
        self.main_person: Optional[DetectedPerson] = main_person
        self.width = width
        self.height = height


class PoseDetector:
    """Thin, thread-safe facade over a MediaPipe PoseLandmarker (IMAGE mode)."""

    def __init__(
        self,
        model_path: Path,
        max_persons: int = 6,
        min_detection_confidence: float = 0.5,
        min_presence_confidence: float = 0.5,
        bbox_min_confidence: float = 0.5,
    ) -> None:
        if not Path(model_path).exists():
            raise FileNotFoundError(
                f"Pose model not found at {model_path}. "
                "Run `python download_model.py` first."
            )

        # Imported lazily so importing this module (e.g. for type hints in tests)
        # does not require the heavy native library until a detector is built.
        import mediapipe as mp
        from mediapipe.tasks import python as mp_python
        from mediapipe.tasks.python import vision

        self._mp = mp
        self._lock = threading.Lock()
        self._bbox_min_confidence = bbox_min_confidence

        base_options = mp_python.BaseOptions(model_asset_path=str(model_path))
        options = vision.PoseLandmarkerOptions(
            base_options=base_options,
            running_mode=vision.RunningMode.IMAGE,
            num_poses=max_persons,
            min_pose_detection_confidence=min_detection_confidence,
            min_pose_presence_confidence=min_presence_confidence,
            output_segmentation_masks=False,
        )
        self._landmarker = vision.PoseLandmarker.create_from_options(options)

    def detect(self, rgb_image: np.ndarray) -> DetectionResult:
        """Run pose detection on an RGB uint8 HxWx3 numpy array."""
        height, width = rgb_image.shape[0], rgb_image.shape[1]
        mp_image = self._mp.Image(
            image_format=self._mp.ImageFormat.SRGB, data=rgb_image
        )

        with self._lock:
            result = self._landmarker.detect(mp_image)

        poses = result.pose_landmarks or []
        people: List[DetectedPerson] = []

        for pose in poses:
            landmarks: List[DetectedLandmark] = []
            # Collect (x, y) only for landmarks we actually trust, so the bbox
            # reflects the *visible* body and is not inflated by points MediaPipe
            # extrapolates off-frame (e.g. legs of a waist-up photo, conf ~0).
            confident_xy: List[tuple] = []
            all_xy: List[tuple] = []
            for idx, lm in enumerate(pose):
                px = lm.x * width
                py = lm.y * height
                # MediaPipe exposes `visibility` (and `presence`); visibility is
                # the per-landmark confidence we surface.
                conf = getattr(lm, "visibility", 0.0)
                if conf is None:
                    conf = 0.0
                conf = float(max(0.0, min(1.0, conf)))
                name = LANDMARK_NAMES[idx] if idx < len(LANDMARK_NAMES) else f"point_{idx}"
                landmarks.append(
                    DetectedLandmark(
                        name=name,
                        x=round(px, 2),
                        y=round(py, 2),
                        x_norm=round(lm.x, 5),
                        y_norm=round(lm.y, 5),
                        z=round(lm.z, 5),
                        confidence=round(conf, 4),
                    )
                )
                all_xy.append((px, py))
                if conf >= self._bbox_min_confidence:
                    confident_xy.append((px, py))

            # Build the bbox from confident points; if none clear the threshold
            # (a person was still detected), fall back to all points so we never
            # emit a degenerate/empty box.
            bbox = self._bbox_from_points(
                confident_xy or all_xy, width, height
            )
            people.append(DetectedPerson(landmarks=landmarks, bbox=bbox))

        # "Main person" = largest *confident* bounding-box area (closest / most
        # prominent), the natural subject for a virtual try-on. Using the
        # confidence-trimmed bbox keeps extrapolated limbs from skewing the pick.
        main = None
        if people:
            main = max(people, key=lambda p: p.bbox[2] * p.bbox[3])

        return DetectionResult(
            person_count=len(people),
            main_person=main,
            width=width,
            height=height,
        )

    @staticmethod
    def _bbox_from_points(points: List[tuple], width: int, height: int) -> tuple:
        """Axis-aligned bbox over (x, y) pixel points, clamped to the image."""
        xs = [p[0] for p in points]
        ys = [p[1] for p in points]
        min_x = max(0.0, min(xs))
        min_y = max(0.0, min(ys))
        max_x = min(float(width), max(xs))
        max_y = min(float(height), max(ys))
        return (
            round(min_x, 2),
            round(min_y, 2),
            round(max(0.0, max_x - min_x), 2),
            round(max(0.0, max_y - min_y), 2),
        )

    def close(self) -> None:
        try:
            self._landmarker.close()
        except Exception:
            pass
