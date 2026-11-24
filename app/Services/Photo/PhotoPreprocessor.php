<?php

namespace App\Services\Photo;

use App\Services\Pose\PoseDetectionException;
use App\Services\Pose\PoseDetector;
use App\Services\Pose\PoseFailureMode;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates input-photo preprocessing (9.5) — the pipeline that runs
 * synchronously at generation submit, BEFORE the budget gate and the async job,
 * so an unsuitable photo is rejected immediately and never spends a FASHN credit.
 *
 * Strict order:
 *   1. normalize() — fixes EXIF orientation FIRST (so pose sees an upright image),
 *      then caps size/format. Pose and crop both operate in this single pixel
 *      space, keeping landmark coordinates consistent end to end.
 *   2. detect()    — pose on the upright image.
 *   3. validate()  — go/no-go with the CATALOG category (upper_body/lower_body/
 *      dresses), NOT the FASHN-mapped one. A no-go is REJECTED.
 *   4. enhance()   — lighting normalization + person segmentation onto a neutral
 *      background (9.5). AFTER validation (only enhance a real person) and BEFORE
 *      crop (frame the clean silhouette). Best-effort: a failure falls back to the
 *      unenhanced bytes so the funnel is never blocked. Dimensions are preserved,
 *      so the pose landmarks stay valid for the crop.
 *   5. crop()      — frame around the person. Best-effort: a crop hiccup never
 *      blocks a photo that already passed validation.
 *
 * Degradation (PoseFailureMode): a pose-service / preprocessing FAILURE — not a
 * validation verdict — is tolerated when fail-open (SKIPPED → original photo, the
 * pre-Phase-9 funnel) and blocks when fail-closed (UNAVAILABLE). Preprocessing is
 * therefore never a single point of failure for the funnel.
 *
 * Privacy: operates on photo BYTES in memory and logs no image content. The
 * detector sends bytes to the internal pose service as multipart (never a public
 * URL); that contract lives in the detector, not here.
 */
class PhotoPreprocessor
{
    public function __construct(
        private readonly PhotoNormalizer $normalizer,
        private readonly PoseDetector $detector,
        private readonly PhotoValidator $validator,
        private readonly PhotoEnhancer $enhancer,
        private readonly PhotoCropper $cropper,
        private readonly PhotoAspectFitter $aspectFitter,
    ) {}

    /**
     * @param  string  $originalContents  Raw uploaded photo bytes.
     * @param  string  $category  CATALOG category of the chosen product.
     */
    public function process(string $originalContents, string $category): PhotoPreprocessResult
    {
        $failureMode = PoseFailureMode::fromConfig();

        // Steps 1–2: orient+normalize, then pose on the upright image. A technical
        // failure here (service down, undecodable bytes) is governed by the failure
        // mode — it is NOT a photo verdict, so the funnel is not held hostage to it.
        try {
            $normalized = $this->normalizer->normalize($originalContents);
            $pose = $this->detector->detect($normalized->contents);
        } catch (PoseDetectionException $e) {
            return $this->degrade($failureMode, 'pose service unavailable');
        } catch (Throwable $e) {
            return $this->degrade($failureMode, 'preprocessing error');
        }

        // Step 3: suitability verdict with the CATALOG category. A real "no" is a
        // rejection shown to the user, regardless of the failure mode. (The empty
        // pose from the Null detector validates as a pass-through, not a reject.)
        $validation = $this->validator->validate($pose, $category);
        if (! $validation->passed) {
            // Invariant: a non-passing ValidationResult always carries a reason.
            return PhotoPreprocessResult::rejected($validation->reason ?? ValidationFailureReason::NoPerson);
        }

        // Step 4: enhance appearance (lighting + segmentation onto a neutral
        // background). Best-effort and dimension-preserving: a failure falls back
        // to the unenhanced bytes so the funnel proceeds, and the crop below still
        // works because the pose landmarks match these (same-size) pixels. Crop
        // therefore frames the clean silhouette when enhancement succeeded.
        $forCrop = $normalized->contents;
        try {
            $enhanced = $this->enhancer->enhance($normalized->contents, $normalized->format);
            $forCrop = $enhanced->contents;
        } catch (Throwable $e) {
            // Mirror the pose fail-open: log the fact only, never the image, and
            // continue with the normalized photo.
            Log::warning('[preprocess] image enhancement failed; using unenhanced photo (fail-open)');
        }

        // Step 5: crop around the person, then (9.6) fit the result to the engine's
        // output aspect so the image we send — and the "Before" we show — matches
        // the generated look with no letterbox bars. Best-effort: a crop/fit failure
        // must never block a photo that already passed validation, so fall back to
        // the (enhanced or normalized) full image.
        try {
            $cropped = $this->cropper->crop($forCrop, $pose, $category);

            // Fit to the target aspect from the FULL pre-crop image so the widened
            // frame uses real surrounding pixels (the crop reused the same pixels,
            // so the crop box maps directly onto $forCrop). Skipped when disabled.
            $contents = $this->aspectFitter->enabled()
                ? $this->aspectFitter->fit($forCrop, $cropped->box, $normalized->format)
                : $cropped->contents;

            // The crop box (if a real crop happened) travels to the front-end so the
            // Before/After slider can frame "Before" to the same region.
            return PhotoPreprocessResult::processed($contents, $normalized->format, $cropped->box);
        } catch (Throwable $e) {
            return PhotoPreprocessResult::processed($forCrop, $normalized->format);
        }
    }

    /**
     * Apply the configured degradation policy to a preprocessing failure: block
     * when fail-closed, otherwise let the funnel proceed with the original photo.
     */
    private function degrade(PoseFailureMode $mode, string $reason): PhotoPreprocessResult
    {
        if ($mode->blocksGeneration()) {
            return PhotoPreprocessResult::unavailable();
        }

        // Fail-open: keep the funnel working with the original, unprocessed photo.
        // Log the fact only — never the image content.
        Log::warning('[preprocess] '.$reason.'; skipping preprocessing (fail-open)');

        return PhotoPreprocessResult::skipped();
    }
}
