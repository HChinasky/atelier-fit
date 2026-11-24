<?php

namespace App\Services\Photo;

use RuntimeException;

/**
 * The enhancement service (lighting / segmentation) could not be reached or
 * returned an error. The caller treats this as fail-open and proceeds with the
 * unenhanced photo. Messages must stay free of private data (no photo bytes).
 */
class PhotoEnhancementException extends RuntimeException {}
