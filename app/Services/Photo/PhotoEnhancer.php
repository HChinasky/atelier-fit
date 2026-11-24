<?php

namespace App\Services\Photo;

/**
 * Seam for input-photo appearance enhancement (Phase 9.5): lighting normalization
 * and person segmentation onto a neutral studio background. Runs AFTER validation
 * (so we only enhance a photo that actually contains a person) and BEFORE cropping
 * (so the crop frames the clean silhouette).
 *
 * It MUST preserve the image dimensions: the cropper (9.4) reuses pose landmark
 * pixel coordinates computed on the pre-enhancement image, so any resize here
 * would silently break the crop geometry. Implementations enhance the BYTES and
 * return the same width/height.
 *
 * Like the pose detector, the heavy work (rembg/OpenCV) lives in the self-hosted
 * pose microservice; the contract exists so it is trivially mockable and so a
 * failure is handled by the caller's fail-open policy rather than crashing the
 * funnel. The photo never leaves our infrastructure.
 */
interface PhotoEnhancer
{
    /**
     * Enhance a (normalized) photo's appearance.
     *
     * @param  string  $imageContents  Image bytes, already normalized (9.3).
     * @param  string  $format  Desired output format ('jpeg' | 'png' | 'webp'),
     *                          normally the normalization format so the pipeline
     *                          stays in one encoding.
     *
     * @throws PhotoEnhancementException on a transport/HTTP failure (the caller
     *                                   applies fail-open and falls back to the
     *                                   unenhanced bytes).
     */
    public function enhance(string $imageContents, string $format = 'jpeg'): EnhancedImage;
}
