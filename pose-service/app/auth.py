"""Optional API-token gate.

When POSE_API_TOKEN is set, every request must carry a matching ``X-API-Token``
header. When it is empty (default for localhost dev) the gate is a no-op.
"""
from __future__ import annotations

import secrets

from fastapi import Header, HTTPException, status

from .config import get_settings


async def require_token(x_api_token: str | None = Header(default=None)) -> None:
    settings = get_settings()
    if not settings.auth_enabled:
        return
    # Constant-time comparison to avoid leaking the token via timing.
    if not x_api_token or not secrets.compare_digest(x_api_token, settings.api_token):
        raise HTTPException(
            status_code=status.HTTP_401_UNAUTHORIZED,
            detail="Invalid or missing X-API-Token header.",
        )
