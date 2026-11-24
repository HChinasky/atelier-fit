<?php

namespace App\Services\Color;

use RuntimeException;

/**
 * The colour service could not be reached, or answered 5xx.
 *
 * A TRANSIENT failure: the photo is fine, our side is not. This is the only
 * condition the job retries on — see App\Jobs\AnalyzeColorProfile.
 */
class ColorAnalysisUnavailable extends RuntimeException {}
