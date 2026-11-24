<?php

namespace App\Services\Photo;

use RuntimeException;

/**
 * The image could not be decoded/processed for cropping. Messages must stay free
 * of private data (no photo bytes).
 */
class PhotoCropException extends RuntimeException {}
