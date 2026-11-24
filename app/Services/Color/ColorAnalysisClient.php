<?php

namespace App\Services\Color;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Talks to pose-service's POST /color-analysis.
 *
 * Same host, same shared secret and same multipart shape as the pose detector —
 * it is one service — but its own timeout: the analysis runs two MediaPipe model
 * sets over the photo and, on the first call after a restart, loads them first.
 *
 * The photo is sent as ORIGINAL bytes. Nothing here normalises, re-encodes or
 * otherwise touches the pixels, because the engine does its own colour
 * management from the file — ICC profile included — and anything done first is
 * measurement error. This is also why the /preprocess-image path, which exists
 * for the try-on flow, is deliberately not reused.
 *
 * Failures are split by whether trying again could help:
 *   - transport error or 5xx -> ColorAnalysisUnavailable (the job retries)
 *   - 4xx or an unparseable body -> ColorAnalysisRefused (it will not)
 * A photo the ENGINE refuses is neither: that arrives as a normal 200.
 *
 * No private data in logs or messages: statuses and counts, never a photo, a
 * filename or a storage key.
 */
class ColorAnalysisClient
{
    /**
     * @param  string  $imageContents  the photo exactly as it was uploaded
     * @param  array<string, mixed>|null  $quiz  self-reported answers, if any
     *
     * @throws ColorAnalysisUnavailable on a transient failure worth retrying
     * @throws ColorAnalysisRefused on a permanent one
     */
    public function analyze(
        string $imageContents,
        ?array $quiz = null,
        bool $debug = false,
        string $filename = 'photo.jpg',
    ): ColorAnalysisResult {
        $fields = ['debug' => $debug ? 'true' : 'false'];
        if ($quiz !== null && $quiz !== []) {
            $fields['quiz'] = json_encode($quiz, JSON_THROW_ON_ERROR);
        }

        try {
            $response = $this->client()
                ->attach('image', $imageContents, $filename)
                ->post('/color-analysis', $fields);
        } catch (Throwable $e) {
            Log::warning('[color] analysis request failed: transport error');

            throw new ColorAnalysisUnavailable('Colour analysis request failed: transport error');
        }

        // 503 means the service is up and told us colour analysis is not
        // available on it — not installed, not configured, switched off. That
        // is permanent until somebody changes the deployment, so it must not be
        // retried; its body carries the reason and is worth passing on.
        if ($response->status() === 503) {
            Log::warning('[color] analysis unavailable on the service');

            throw new ColorAnalysisRefused(
                'Colour analysis is not available on the service: '.$this->detail($response)
            );
        }

        if ($response->serverError()) {
            Log::warning('[color] analysis service error', ['status' => $response->status()]);

            throw new ColorAnalysisUnavailable(
                'Colour analysis service returned '.$response->status()
            );
        }

        if ($response->failed()) {
            Log::warning('[color] analysis rejected', ['status' => $response->status()]);

            throw new ColorAnalysisRefused(
                'Colour analysis rejected the request with status '.$response->status()
            );
        }

        return $this->parse($response->json());
    }

    /**
     * Re-decide stored measurements against the thresholds as they stand now.
     *
     * POST /color-rule-check. No photo, no models, nothing stored: the numbers
     * go over, the engine's own rules run on them, and the verdicts come back.
     *
     * The rules stay on that side ON PURPOSE. Re-implementing them here would
     * give a checker that drifts from the engine it is checking, and a
     * rule-check that disagrees with the engine tells an operator about itself
     * and nothing else.
     *
     * @param  array<string, mixed>  $samples  a profile's `samples` section, unchanged
     * @return array<string, mixed> `thresholds` and `zones`, as the service sends them
     *
     * @throws ColorAnalysisUnavailable on a transient failure worth retrying
     * @throws ColorAnalysisRefused on a permanent one
     */
    public function ruleCheck(array $samples): array
    {
        try {
            $response = $this->client()->post('/color-rule-check', ['samples' => $samples]);
        } catch (Throwable) {
            Log::warning('[color] rule check failed: transport error');

            throw new ColorAnalysisUnavailable('Rule check request failed: transport error');
        }

        if ($response->status() === 503) {
            throw new ColorAnalysisRefused(
                'Rule checking is not available on the service: '.$this->detail($response)
            );
        }

        if ($response->serverError()) {
            throw new ColorAnalysisUnavailable('Rule check returned '.$response->status());
        }

        if ($response->failed()) {
            throw new ColorAnalysisRefused(
                'Rule check rejected the request: '.$this->detail($response)
            );
        }

        $body = $response->json();
        if (! is_array($body) || ! is_array($body['zones'] ?? null)) {
            throw new ColorAnalysisRefused('Rule check returned a body without zones.');
        }

        return $body;
    }

    /**
     * Measure the colours of a catalog product photo.
     *
     * POST /garment-colour. The same service, switch and failure split as the
     * face: a photo the engine could not measure is a normal 200 carrying
     * status="rejected"; transport errors and 5xx are worth another go; a 503
     * (colour analysis off) and any 4xx are not.
     *
     * @return array<string, mixed> the engine's GarmentResult, whole
     *
     * @throws ColorAnalysisUnavailable on a transient failure worth retrying
     * @throws ColorAnalysisRefused on a permanent one
     */
    public function analyzeGarment(string $imageContents, string $filename = 'product.jpg'): array
    {
        try {
            $response = $this->client()
                ->timeout((int) config('color_analysis.garment_fetch_timeout', 20) * 3)
                ->attach('image', $imageContents, $filename)
                ->post('/garment-colour');
        } catch (Throwable) {
            Log::warning('[color] garment request failed: transport error');

            throw new ColorAnalysisUnavailable('Garment colour request failed: transport error');
        }

        if ($response->status() === 503) {
            throw new ColorAnalysisRefused(
                'Garment colour is not available on the service: '.$this->detail($response)
            );
        }

        if ($response->serverError()) {
            throw new ColorAnalysisUnavailable('Garment colour service returned '.$response->status());
        }

        if ($response->failed()) {
            throw new ColorAnalysisRefused('Garment colour rejected the request: '.$this->detail($response));
        }

        $result = $response->json('result');
        if (! is_array($result) || ! is_string($result['status'] ?? null)) {
            throw new ColorAnalysisRefused('Garment colour returned a body without a result.');
        }

        return $result;
    }

    /**
     * The service's own explanation, when it gave one.
     *
     * Passed through to the profile's error so an operator reading the admin
     * page learns what to fix without going to the service's logs. It is our
     * own internal service, so its wording is safe to show; it never contains
     * anything derived from the photograph.
     */
    private function detail(Response $response): string
    {
        $detail = $response->json('detail');

        return is_string($detail) && $detail !== '' ? $detail : 'no reason given.';
    }

    /**
     * Map the response body, validating only what we depend on.
     *
     * Deliberately shallow: `result` is the engine's contract and stays whole,
     * because reshaping it here would mean editing this class every time the
     * engine grows a field. What is checked is that it IS an object with a
     * status — anything else means we are not talking to the service we think.
     */
    private function parse(mixed $data): ColorAnalysisResult
    {
        if (! is_array($data) || ! is_array($data['result'] ?? null)) {
            throw new ColorAnalysisRefused('Colour analysis returned a body without a result.');
        }

        $result = $data['result'];
        if (! is_string($result['status'] ?? null)) {
            throw new ColorAnalysisRefused('Colour analysis result has no status.');
        }

        $images = [];
        foreach ((array) ($data['debug_images'] ?? []) as $name => $encoded) {
            if (! is_string($name) || ! is_string($encoded)) {
                continue;
            }
            $decoded = base64_decode($encoded, true);
            if ($decoded !== false && $decoded !== '') {
                $images[$name] = $decoded;
            }
        }

        return new ColorAnalysisResult(result: $result, images: $images);
    }

    /**
     * A client pinned to the pose service, carrying the same optional shared
     * secret as /pose and the colour-specific timeout.
     */
    private function client(): PendingRequest
    {
        $request = Http::baseUrl((string) config('services.pose.url'))
            ->acceptJson()
            ->timeout((int) config('services.pose.color_timeout', 180));

        $token = (string) config('services.pose.token');
        if ($token !== '') {
            $request = $request->withHeaders(['X-API-Token' => $token]);
        }

        return $request;
    }
}
