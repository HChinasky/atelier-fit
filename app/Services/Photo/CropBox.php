<?php

namespace App\Services\Photo;

/**
 * The rectangle a photo was cropped to (9.4), expressed as FRACTIONS of the
 * normalized image (0..1) plus the rectangle's own pixel aspect ratio.
 *
 * Fractions (not pixels) so the box is resolution-independent: the EXIF-oriented,
 * downscale-only normalization keeps the aspect ratio, so these same fractions map
 * onto the browser's EXIF-oriented copy of the original photo. That lets the
 * front-end frame the "Before" image to the EXACT region we sent to the engine,
 * so the Before/After slider shares one frame instead of jumping between the whole
 * uncropped photo and the cropped result.
 *
 * Pure geometry — numbers only, never any image content — so it is safe to return
 * to the client.
 */
final readonly class CropBox
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public float $aspect,
    ) {}

    /**
     * Build from the pixel rectangle [left, top, width, height] and the source
     * image dimensions. Guards against a zero-sized image (returns a full-frame
     * box) so the math can never divide by zero.
     *
     * @param  array{0:int,1:int,2:int,3:int}  $box
     */
    public static function fromPixels(array $box, int $imgW, int $imgH): self
    {
        [$left, $top, $width, $height] = $box;

        if ($imgW <= 0 || $imgH <= 0 || $width <= 0 || $height <= 0) {
            return new self(0.0, 0.0, 1.0, 1.0, 1.0);
        }

        return new self(
            x: $left / $imgW,
            y: $top / $imgH,
            width: $width / $imgW,
            height: $height / $imgH,
            aspect: $width / $height,
        );
    }

    /**
     * @return array{x: float, y: float, width: float, height: float, aspect: float}
     */
    public function toArray(): array
    {
        return [
            'x' => $this->x,
            'y' => $this->y,
            'width' => $this->width,
            'height' => $this->height,
            'aspect' => $this->aspect,
        ];
    }
}
