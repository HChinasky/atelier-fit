<?php

namespace App\Services\Pose;

use RuntimeException;

/**
 * The pose service could not produce a usable result: transport failure /
 * timeout, a non-2xx response, or an unparseable body. A person-not-found case
 * is NOT an error — that comes back as a valid PoseResult with personCount 0.
 *
 * Messages must stay free of private data (no photo bytes, no file contents).
 * How callers react to this is governed by PoseFailureMode (fail-open vs
 * fail-closed), applied in 9.2/9.5 — not here.
 */
class PoseDetectionException extends RuntimeException {}
