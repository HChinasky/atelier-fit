<?php

namespace App\Services\Fashn;

use RuntimeException;

/**
 * API-level failure from FASHN: the request was rejected outright (auth,
 * validation, quota) or returned no usable prediction id. Distinct from a
 * runtime failure, which arrives as TryOnStatus with status === "failed".
 *
 * Messages must stay free of private data (no photo bytes, no signed URLs).
 */
class TryOnException extends RuntimeException {}
