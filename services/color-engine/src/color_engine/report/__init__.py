"""HTML debug report for the CLI."""

from __future__ import annotations

from .builder import CorrectionRow, ReportEntry, ZoneRow, build_entry, build_report
from .stability import PersonStability, PhotoStability, build_stability, delta_e, summarise

__all__ = [
    "CorrectionRow",
    "PersonStability",
    "PhotoStability",
    "ReportEntry",
    "ZoneRow",
    "build_entry",
    "build_report",
    "build_stability",
    "delta_e",
    "summarise",
]
