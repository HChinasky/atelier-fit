<?php

namespace App\Services\Photo;

/**
 * A photo after geometry normalization (9.3): the processed bytes plus their
 * final dimensions and format. Cropping (9.4) consumes the dimensions, so they
 * MUST reflect the post-orientation, post-resize image (e.g. width/height are
 * swapped when EXIF orientation rotated the photo 90°).
 */
final readonly class NormalizedImage
{
    public function __construct(
        public string $contents,
        public int $width,
        public int $height,
        public string $format,
    ) {}
}
