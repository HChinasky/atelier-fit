<?php

namespace App\Services\Photo;

/**
 * A photo after person-aware cropping (9.4): the processed bytes plus their final
 * dimensions. `cropped` is false when the safe fallback returned the photo as-is
 * (too few reliable landmarks to crop confidently) — callers can tell a real crop
 * from a pass-through without comparing bytes.
 *
 * `box` carries the crop rectangle (as fractions of the source image) when a real
 * crop happened, and null on the pass-through fallback. The front-end uses it to
 * frame the "Before" image to the same region for the Before/After slider.
 */
final readonly class CroppedImage
{
    public function __construct(
        public string $contents,
        public int $width,
        public int $height,
        public bool $cropped,
        public ?CropBox $box = null,
    ) {}
}
