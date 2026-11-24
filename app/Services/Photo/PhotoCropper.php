<?php

namespace App\Services\Photo;

use App\Services\Pose\PoseResult;

/**
 * Seam for person-aware cropping (9.4) — the final preprocessing step, applied
 * AFTER validation (9.2) and geometry normalization (9.3). It crops the photo
 * around the person using pose landmarks so FASHN receives a well-framed subject
 * (head and, for full-body looks, feet kept; sensible margins; category-aware).
 *
 * Pure geometry on our server: Intervention Image, no ML, no external calls, the
 * photo never leaves. It does NOT distort — only ever crops within the image. The
 * call order is wired in 9.5; this service is built to run last.
 */
interface PhotoCropper
{
    /**
     * Crop a (normalized) photo around the detected person.
     *
     * @param  string  $imageContents  Image bytes, already normalized (9.3). Pixel
     *                                 coordinates in $pose are in this image's space.
     * @param  PoseResult  $pose  Pose data for the SAME photo (bbox + landmarks).
     * @param  string  $category  Catalog category — `dresses` | `lower_body` |
     *                            `upper_body`. The catalog view, NOT the FASHN-mapped
     *                            one (one-pieces/bottoms/tops).
     *
     * @throws PhotoCropException if the bytes cannot be decoded.
     */
    public function crop(string $imageContents, PoseResult $pose, string $category): CroppedImage;
}
