"""Measure the pose-service process memory at each stage of a worst-case request.

Run ON THE SERVER (CX33) to confirm pose + rembg fit in RAM without OOM:

    cd /var/www/fitting-room/pose-service
    .venv/bin/python bench_memory.py

It loads the MediaPipe pose model (as the live service keeps it), runs a pose
detection, then runs a rembg segmentation alongside it (the moment both models
are resident — the memory worst case), and finally releases rembg to show the
steady state that SEGMENT_KEEP_LOADED=false returns to after every call.

Pair it with `free -h` in another shell during the "both in memory" line to read
the whole-box headroom (Postgres + Redis + Horizon + this service + the OS).
"""
from __future__ import annotations

import gc
import os
import sys
from pathlib import Path

import numpy as np
from PIL import Image

_ROOT = Path(__file__).resolve().parent


def rss_mib() -> float:
    """Resident set size in MiB, cross-platform."""
    if sys.platform.startswith("linux"):
        for line in Path("/proc/self/status").read_text().splitlines():
            if line.startswith("VmRSS:"):
                return int(line.split()[1]) / 1024.0
        return 0.0
    # Windows fallback.
    import ctypes
    import ctypes.wintypes as w

    class PMC(ctypes.Structure):
        _fields_ = [
            ("cb", w.DWORD), ("PageFaultCount", w.DWORD),
            ("PeakWorkingSetSize", ctypes.c_size_t), ("WorkingSetSize", ctypes.c_size_t),
            ("QuotaPeakPagedPoolUsage", ctypes.c_size_t), ("QuotaPagedPoolUsage", ctypes.c_size_t),
            ("QuotaPeakNonPagedPoolUsage", ctypes.c_size_t), ("QuotaNonPagedPoolUsage", ctypes.c_size_t),
            ("PagefileUsage", ctypes.c_size_t), ("PeakPagefileUsage", ctypes.c_size_t),
        ]

    k = ctypes.windll.kernel32
    k.GetCurrentProcess.restype = w.HANDLE
    k.K32GetProcessMemoryInfo.argtypes = [w.HANDLE, ctypes.POINTER(PMC), w.DWORD]
    k.K32GetProcessMemoryInfo.restype = w.BOOL
    c = PMC()
    c.cb = ctypes.sizeof(c)
    k.K32GetProcessMemoryInfo(k.GetCurrentProcess(), ctypes.byref(c), c.cb)
    return c.WorkingSetSize / 1048576.0


def _report(label: str) -> None:
    print(f"{label:<28} rss={rss_mib():7.1f} MiB", flush=True)


def main() -> int:
    # A real person photo. Override with BENCH_IMAGE; defaults to a dev fixture.
    img_path = os.getenv("BENCH_IMAGE") or str(_ROOT.parent / "public" / "dev-catalog" / "woman-model.jpg")
    img = np.asarray(Image.open(img_path).convert("RGB"))

    _report("baseline")

    from app.config import get_settings
    from app.detector import PoseDetector

    settings = get_settings()
    detector = PoseDetector(model_path=settings.model_path)
    detector.detect(img)
    _report("+ mediapipe (resident)")

    from app import enhance

    # keep_loaded=True here so both models are in memory at once — the worst case.
    enhance.segment_on_background(
        img, model_name=settings.segment_model, bg_color=settings.bg_color, keep_loaded=True
    )
    _report("+ rembg (both resident)")

    enhance._release_session()
    gc.collect()
    _report("rembg released (steady)")

    print(
        "\nThe live service runs with SEGMENT_KEEP_LOADED=false, so it sits at the "
        "'mediapipe (resident)' level between generations and only briefly peaks at "
        "the 'both resident' level during a segmentation. Watch `free -h` against that "
        "peak; it must stay out of swap with Postgres/Redis/Horizon also running."
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
