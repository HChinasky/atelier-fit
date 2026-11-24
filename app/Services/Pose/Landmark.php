<?php

namespace App\Services\Pose;

/**
 * A single body keypoint returned by the pose service, decoupled from the raw
 * HTTP body so consumers (validation in 9.2, cropping in 9.4) reason in domain
 * terms.
 *
 * `confidence` is the keypoint's visibility probability in [0,1]. It HONESTLY
 * reflects whether the point is really seen or extrapolated off-frame: a cropped
 * ankle comes back with near-zero confidence, a visible one near 1. Downstream
 * "is the full body visible" decisions read this — the detector itself judges
 * nothing.
 *
 * Coordinates: x/y are pixels in the source image; xNorm/yNorm are normalized to
 * [0,1]; z is relative depth in MediaPipe units (negative is closer to camera).
 */
final readonly class Landmark
{
    public function __construct(
        public string $name,
        public float $x,
        public float $y,
        public float $xNorm,
        public float $yNorm,
        public float $z,
        public float $confidence,
    ) {}
}
