"""Single-command entrypoint that honours POSE_SERVICE_HOST / POSE_SERVICE_PORT.

    python run.py

Use this when you want host/port driven by the environment (.env). For
auto-reload during development, use the uvicorn CLI instead:

    python -m uvicorn app.main:app --reload --host 127.0.0.1 --port 8000
"""
from __future__ import annotations

import uvicorn

from app.config import get_settings


def main() -> None:
    settings = get_settings()
    uvicorn.run(
        "app.main:app",
        host=settings.host,
        port=settings.port,
        log_level="info",
    )


if __name__ == "__main__":
    main()
