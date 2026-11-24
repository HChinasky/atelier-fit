<?php

namespace App\Services\Color;

use RuntimeException;

/**
 * The colour service understood the request and will not serve it: a 4xx, a 503
 * because colour analysis is not configured on that deployment, or a body we
 * cannot parse.
 *
 * PERMANENT: sending the identical request again gets the identical answer, so
 * retrying only burns a worker. A 503 belongs here rather than with the
 * transient failures precisely because of what it means on THIS endpoint —
 * the package or its models are not installed, or the switch is off, and none
 * of that changes in the next sixty seconds.
 *
 * Note this is NOT a refused *photo* — the engine reports that inside a 200,
 * as status="rejected".
 */
class ColorAnalysisRefused extends RuntimeException {}
