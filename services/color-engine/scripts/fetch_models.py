"""Download the MediaPipe model artifacts into ``models/``.

    python scripts/fetch_models.py

Build artifacts, not source: they are gitignored and fetched on demand, the same
way pose-service handles its pose landmarker.

Both URLs are pinned to an explicit model version (``/1/``) rather than
``/latest/``, so the checksums below stay valid. The checksums were computed
from the first successful download and are asserted on every run - if Google
ever republishes a different file under the same URL, this fails loudly instead
of silently changing everyone's colour readings.

This script is the only place in the package that touches the network.
"""

from __future__ import annotations

import hashlib
import sys
import urllib.request
from pathlib import Path
from typing import NamedTuple

SERVICE_ROOT = Path(__file__).resolve().parent.parent
MODELS_DIR = SERVICE_ROOT / "models"


class Artifact(NamedTuple):
    filename: str
    url: str
    sha256: str
    note: str


ARTIFACTS: tuple[Artifact, ...] = (
    Artifact(
        filename="face_landmarker.task",
        url=(
            "https://storage.googleapis.com/mediapipe-models/face_landmarker/"
            "face_landmarker/float16/1/face_landmarker.task"
        ),
        sha256="64184e229b263107bc2b804c6625db1341ff2bb731874b0bcc2fe6544e0bc9ff",
        note="478 landmarks (468 mesh + 2x5 iris), ~3.8 MB",
    ),
    Artifact(
        filename="selfie_multiclass_256x256.tflite",
        url=(
            "https://storage.googleapis.com/mediapipe-models/image_segmenter/"
            "selfie_multiclass_256x256/float32/1/selfie_multiclass_256x256.tflite"
        ),
        sha256="c6748b1253a99067ef71f7e26ca71096cd449baefa8f101900ea23016507e0e0",
        note="background/hair/body-skin/face-skin/clothes/others, ~16 MB",
    ),
)


def sha256_of(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def fetch(artifact: Artifact, *, force: bool = False) -> bool:
    """Download one artifact if needed and verify it. True when it is good."""
    destination = MODELS_DIR / artifact.filename
    MODELS_DIR.mkdir(parents=True, exist_ok=True)

    if destination.exists() and destination.stat().st_size > 0 and not force:
        actual = sha256_of(destination)
        if actual == artifact.sha256:
            print(f"ok      {artifact.filename} (already present)")
            return True
        print(f"stale   {artifact.filename}: checksum mismatch, re-downloading")

    print(f"fetch   {artifact.filename} - {artifact.note}")
    temporary = destination.with_suffix(destination.suffix + ".part")
    urllib.request.urlretrieve(artifact.url, temporary)

    actual = sha256_of(temporary)
    if actual != artifact.sha256:
        temporary.unlink(missing_ok=True)
        print(
            f"FAIL    {artifact.filename}: sha256 {actual}\n"
            f"        expected {artifact.sha256}\n"
            "        The published model changed. Verify the new file, then update\n"
            "        the checksum in scripts/fetch_models.py deliberately.",
            file=sys.stderr,
        )
        return False

    temporary.replace(destination)
    print(f"ok      {artifact.filename} ({destination.stat().st_size} bytes)")
    return True


def main(argv: list[str] | None = None) -> int:
    args = argv if argv is not None else sys.argv[1:]
    force = "--force" in args
    results = [fetch(artifact, force=force) for artifact in ARTIFACTS]
    if not all(results):
        return 1
    print(f"\nModels ready in {MODELS_DIR}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
