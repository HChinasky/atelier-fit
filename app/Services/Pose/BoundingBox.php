<?php

namespace App\Services\Pose;

/**
 * Axis-aligned bounding box of a person, in source-image pixels.
 *
 * The pose service builds it from confident landmarks only (extrapolated /
 * off-frame points are excluded) and clamps it to the image, so it tracks the
 * VISIBLE body. Note: smart cropping (9.4) does NOT crop to this box directly —
 * it derives its own frame from individual reliable landmarks (a clamped box can
 * still be stretched to the image edge by a confident off-frame point).
 */
final readonly class BoundingBox
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {}
}
