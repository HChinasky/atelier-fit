"""Exception hierarchy.

The rule the whole package follows:

* An **expected** problem with the photo (no face, too small, too dark) is *not*
  an exception. It is an ``AnalysisResult`` with ``status="rejected"`` and a
  ``reject_reason``. Callers render those to the user.
* An exception means the environment or our own code is broken (missing model
  file, unreadable config). Those are for operators, not users.

The two input errors below sit on the seam: ``io`` raises them while decoding,
and ``Engine`` converts them into a rejected result via ``reject_reason``.
"""

from __future__ import annotations

from .models import RejectReason


class ColorEngineError(Exception):
    """Base class for everything this package raises."""


class ConfigurationError(ColorEngineError):
    """The engine cannot start: bad settings, unusable paths."""


class ModelFileNotFound(ConfigurationError):
    """A MediaPipe model artifact is missing from the models directory."""

    def __init__(self, path: object) -> None:
        super().__init__(
            f"MediaPipe model not found at {path}. "
            "Run `python scripts/fetch_models.py` to download it."
        )
        self.path = path


class ImageInputError(ColorEngineError):
    """Input bytes cannot be turned into an image we are willing to analyse.

    Carries the ``reject_reason`` the engine reports instead of propagating.
    """

    reject_reason: RejectReason = RejectReason.UNSUPPORTED_FORMAT


class UnsupportedFormat(ImageInputError):
    reject_reason = RejectReason.UNSUPPORTED_FORMAT


class FileTooLarge(ImageInputError):
    reject_reason = RejectReason.FILE_TOO_LARGE


class ImageTooSmall(ImageInputError):
    reject_reason = RejectReason.LOW_RESOLUTION
