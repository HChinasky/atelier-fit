<?php

namespace App\Services\Photo;

/**
 * Fits the (normalized) photo to the aspect ratio the VTON engine generates, so
 * the image we send — and therefore the "Before" we show — shares one frame with
 * the result instead of letterboxing with side/top bars in the Before/After
 * slider.
 *
 * It widens (or heightens) the person's crop region to the target aspect using
 * the photo's REAL surrounding pixels (studio margins), and only pads with a
 * neutral colour where the photo runs out — so it never crops the person and
 * avoids fake panels whenever the original has room. Pure geometry on our server;
 * no ML, no external calls, the photo never leaves.
 *
 * Runs AFTER cropping (9.4): it reuses the crop box to know where the person is,
 * but re-derives the final image from the full normalized photo so it can pull in
 * the real margins the crop trimmed off.
 */
interface PhotoAspectFitter
{
    /**
     * Whether aspect fitting is configured (a target aspect is set). When false,
     * the caller keeps the cropper's output unchanged.
     */
    public function enabled(): bool;

    /**
     * Fit the normalized photo to the target aspect, centered on the crop region.
     *
     * @param  string  $normalizedContents  The full normalized image bytes (9.3) —
     *                                      the same pixel space the crop box is in.
     * @param  CropBox|null  $box  The person crop region (fractions), or null when
     *                             no crop happened (then the whole photo is fitted).
     * @param  string  $format  Output format ('jpeg'|'png'|'webp').
     *
     * @throws PhotoCropException if the bytes cannot be decoded.
     */
    public function fit(string $normalizedContents, ?CropBox $box, string $format): string;
}
