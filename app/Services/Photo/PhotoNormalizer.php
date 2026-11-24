<?php

namespace App\Services\Photo;

/**
 * Seam for input-photo geometry normalization (9.3), mirroring the other Phase 9
 * services. Pure geometry — fix EXIF orientation, cap size (aspect-preserving
 * downscale, never stretch/upscale), encode to a target format. No ML, no
 * external calls, no person/bbox cropping (that is 9.4); the photo stays on our
 * server.
 *
 * Orientation must be fixed EARLY — before pose detection — so the detector and
 * FASHN both see an upright image. The exact call order is wired in 9.5; this
 * service is built to be applied first.
 */
interface PhotoNormalizer
{
    /**
     * Normalize a photo's geometry.
     *
     * @param  string  $imageContents  Raw image bytes.
     *
     * @throws PhotoNormalizationException if the bytes cannot be decoded.
     */
    public function normalize(string $imageContents): NormalizedImage;
}
