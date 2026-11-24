<?php

namespace App\Services\Fashn;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * FASHN Try-On implementation. Talks to POST /v1/run and GET /v1/status/<id>
 * per the FASHN v1 spec, authenticated with a Bearer key from config. The
 * tunables (model_name, generation_mode) come from config('services.fashn');
 * nothing is hardcoded here.
 *
 * Request shape targets the tryon-max model: inputs are model_image (the PERSON),
 * product_image (the garment) and generation_mode. tryon-max auto-detects the
 * garment category and returns a single image, so no category / num_samples /
 * moderation_level / output_format are sent (they are not part of its schema).
 *
 * No private data ever appears in thrown messages (no photo bytes, no signed URLs).
 */
class FashnTryOnProvider implements TryOnProvider
{
    public function run(string $modelImageUrl, string $garmentImageUrl, array $options = []): string
    {
        $inputs = array_merge([
            // model_image is the PERSON photo; product_image is the catalog item.
            'model_image' => $modelImageUrl,
            'product_image' => $garmentImageUrl,
            'generation_mode' => $options['generation_mode'] ?? config('services.fashn.mode'),
        ], $options['inputs'] ?? []);

        return $this->submit([
            'model_name' => $options['model_name'] ?? config('services.fashn.model_name'),
            'inputs' => $inputs,
        ]);
    }

    public function changeBackground(string $imageUrl, string $prompt, array $options = []): string
    {
        // Background Change runs on the same /v1/run endpoint with a different
        // model_name; its input is the source image plus a scene prompt.
        $inputs = array_merge([
            'image' => $imageUrl,
            'prompt' => $prompt,
            'output_format' => $options['output_format'] ?? config('services.fashn.output_format'),
        ], $options['inputs'] ?? []);

        return $this->submit([
            'model_name' => $options['model_name'] ?? config('services.fashn.background.model_name'),
            'inputs' => $inputs,
        ]);
    }

    /**
     * POST a /v1/run payload and return the prediction id. Shared by run() and
     * changeBackground() so both get identical transport- and API-error handling.
     * No private data ever appears in a thrown message (no payload, no URLs).
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws TryOnException on a transport error, a rejected request, or no id.
     */
    private function submit(array $payload): string
    {
        try {
            $response = $this->client()->post('/run', $payload);
        } catch (Throwable $e) {
            // Network/transport failure — surface without leaking the payload.
            throw new TryOnException('FASHN run request failed: transport error');
        }

        if ($response->failed()) {
            throw new TryOnException('FASHN run request rejected with status '.$response->status());
        }

        $data = $response->json();

        if (! empty($data['error']) || empty($data['id'])) {
            throw new TryOnException('FASHN run returned no prediction id');
        }

        return (string) $data['id'];
    }

    public function status(string $predictionId): TryOnStatus
    {
        try {
            $response = $this->client()->get('/status/'.$predictionId);
        } catch (Throwable $e) {
            throw new TryOnException('FASHN status request failed: transport error');
        }

        if ($response->failed()) {
            throw new TryOnException('FASHN status request rejected with status '.$response->status());
        }

        $data = $response->json();

        return new TryOnStatus(
            status: (string) ($data['status'] ?? 'unknown'),
            outputUrls: array_values((array) ($data['output'] ?? [])),
            error: $this->errorMessage($data['error'] ?? null),
        );
    }

    /**
     * Download a finished look from FASHN's CDN.
     *
     * A plain fetch: FASHN returns an ordinary url and offers no per-request
     * access control to restrict it. What it does offer is `return_base64`,
     * which skips the CDN entirely and cuts FASHN's own retention from about
     * three days to sixty minutes — see config/services.php.
     */
    public function downloadResult(string $url): string
    {
        try {
            $response = Http::timeout((int) config('services.fashn.timeout', 30))->get($url);
        } catch (Throwable $e) {
            throw new TryOnException('fashn result download failed: transport error');
        }

        if ($response->failed()) {
            throw new TryOnException('fashn result download rejected with status '.$response->status());
        }

        return $response->body();
    }

    /**
     * A Bearer-authenticated JSON client pinned to the FASHN base URL.
     */
    private function client(): PendingRequest
    {
        return Http::baseUrl((string) config('services.fashn.base_url'))
            ->withToken((string) config('services.fashn.key'))
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.fashn.timeout', 30));
    }

    /**
     * FASHN's error may be a string or an object ({name, message}); normalize to
     * a short message string, or null when there is no error.
     */
    private function errorMessage(mixed $error): ?string
    {
        if (blank($error)) {
            return null;
        }

        if (is_array($error)) {
            return (string) ($error['message'] ?? $error['name'] ?? 'unknown error');
        }

        return (string) $error;
    }
}
