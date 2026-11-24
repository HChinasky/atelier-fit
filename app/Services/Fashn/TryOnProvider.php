<?php

namespace App\Services\Fashn;

/**
 * Seam for the virtual try-on engine (FASHN), mirroring the FeedParser /
 * LeadNotifier pattern so the job depends on the contract, not the vendor.
 *
 * Two steps, because the engine is asynchronous: run() submits a job and returns
 * a prediction id; status() is polled until the prediction reaches a terminal
 * state. API-level failures (request rejected, no id) surface as a TryOnException;
 * runtime failures (status === "failed") come back through TryOnStatus.
 *
 * changeBackground() is the OPTIONAL Phase 5 second step. It runs on the very
 * same /v1/run + /v1/status machinery (different model_name), submits the
 * try-on result image plus a scene prompt, and is polled with the same status().
 * Format CONFIRMED by a live FASHN call: model_name "background-change",
 * inputs = { image, prompt }, output is an array of URLs.
 */
interface TryOnProvider
{
    /**
     * Submit a try-on generation.
     *
     * @param  string  $modelImageUrl  URL of the PERSON photo (the user).
     * @param  string  $garmentImageUrl  URL of the garment photo (catalog item).
     * @param  array<string, mixed>  $options  category, num_samples, mode, etc.
     * @return string The prediction id to poll.
     *
     * @throws TryOnException on an API-level failure (no prediction id).
     */
    public function run(string $modelImageUrl, string $garmentImageUrl, array $options = []): string;

    /**
     * Submit a background-change run for a single image (OPTIONAL Phase 5 step).
     *
     * Same engine, same polling: returns a prediction id to poll with status().
     * Billed +1 credit per call. FASHN marks this model's lifecycle experimental
     * (the contract may change), so callers must treat a failure as best-effort
     * (keep the original image), never fatal.
     *
     * @param  string  $imageUrl  The image to re-background (a try-on result URL).
     * @param  string  $prompt  Natural-language scene description for the new background.
     * @param  array<string, mixed>  $options  model_name, inputs overrides, etc.
     * @return string The prediction id to poll.
     *
     * @throws TryOnException on an API-level failure (no prediction id).
     */
    public function changeBackground(string $imageUrl, string $prompt, array $options = []): string;

    /**
     * Fetch the current state of a prediction.
     *
     * @throws TryOnException if the status request itself fails at the API level.
     */
    public function status(string $predictionId): TryOnStatus;

    /**
     * Download a finished result so it can be moved into our own storage.
     *
     * It belongs to the provider rather than the job because HOW a result is
     * fetched is a vendor detail: a plain public url for one engine, an
     * access-controlled url needing a short-lived token for another. The job only
     * wants the bytes.
     *
     * @return string Raw image bytes.
     *
     * @throws TryOnException on a transport or non-2xx failure. The url is never
     *                        included in the message — it can carry a token.
     */
    public function downloadResult(string $url): string;
}
