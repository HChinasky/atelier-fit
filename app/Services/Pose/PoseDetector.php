<?php

namespace App\Services\Pose;

/**
 * Seam for body-pose detection, mirroring the TryOnProvider / FeedParser /
 * LeadNotifier pattern so consumers depend on the contract, not the vendor or
 * the transport. One pose result feeds both photo validation (9.2) and smart
 * cropping (9.4).
 *
 * The detector is a PURE detector: it returns facts about the image
 * (person_count, the main person's bounding box and landmarks, image
 * dimensions). It makes NO suitability judgement — that policy lives in 9.2.
 *
 * Privacy: detect() receives the photo BYTES and the implementation sends them
 * to the (internal, self-hosted) pose service as a direct multipart upload. No
 * public or pre-signed URL is ever used, and photo contents are never logged.
 * The bytes handed in are the user's ORIGINAL uploaded photo; in 9.5 the flow
 * will read those same bytes from private R2 storage (no extra public copy) and
 * pass them here before the generation step.
 */
interface PoseDetector
{
    /**
     * Detect poses in a photo.
     *
     * @param  string  $imageContents  Raw image bytes (the user's photo).
     * @param  string  $filename  A filename hint for the multipart part (no path,
     *                            no private data); only its extension matters.
     *
     * @throws PoseDetectionException on transport failure, timeout, a non-2xx
     *                                response, or an unparseable body. A photo
     *                                with no person is NOT an error — it returns a
     *                                PoseResult with personCount 0.
     */
    public function detect(string $imageContents, string $filename = 'photo.jpg'): PoseResult;
}
