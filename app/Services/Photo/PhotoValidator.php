<?php

namespace App\Services\Photo;

use App\Services\Pose\PoseResult;

/**
 * Seam for the input-photo suitability gate (9.2), mirroring the PoseDetector /
 * TryOnProvider pattern. It turns pose facts into a go/no-go decision BEFORE a
 * photo reaches FASHN, so we neither show poor results nor burn credits on
 * unusable photos.
 *
 * The requirements depend on the garment CATEGORY: a dress or trousers needs the
 * full body (legs visible), a top only needs a visible torso — so the same
 * waist-up photo can pass for a top yet fail for a dress.
 *
 * This is pure decision logic. It does NOT call the pose service or FASHN, does
 * NOT crop, and is not wired into the generation flow (that is 9.5).
 */
interface PhotoValidator
{
    /**
     * Decide whether a photo is suitable to try the given garment category on.
     *
     * @param  PoseResult  $pose  Result from the pose detector (9.1).
     * @param  string  $category  Catalog category: upper_body | lower_body | dresses.
     */
    public function validate(PoseResult $pose, string $category): ValidationResult;
}
