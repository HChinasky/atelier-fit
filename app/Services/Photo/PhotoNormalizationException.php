<?php

namespace App\Services\Photo;

use RuntimeException;

/**
 * The image could not be decoded/processed for normalization. Messages must stay
 * free of private data (no photo bytes).
 */
class PhotoNormalizationException extends RuntimeException {}
