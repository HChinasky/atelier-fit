<?php

namespace App\Services\Photo;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Calls the self-hosted pose microservice's POST /preprocess-image to enhance the
 * input photo (lighting normalization + person segmentation onto a neutral
 * background). It shares the pose service's base URL and token (same process,
 * embedded to avoid a second service and extra RAM) but uses its own, longer
 * timeout because rembg adds a couple of CPU seconds on top of the call.
 *
 * The photo is sent as a multipart upload (field "image"); the two step toggles
 * travel as form fields. Which steps to request comes from config('photo.
 * enhancement'); nothing is hardcoded. The service returns the enhanced image
 * BYTES with X-Applied-* headers reporting what actually ran.
 *
 * Privacy: logs only the fact of a failure and safe metadata (HTTP status),
 * never the photo bytes. The image is reached by direct multipart upload, never a
 * public/pre-signed URL.
 */
class PoseServiceImageEnhancer implements PhotoEnhancer
{
    public function enhance(string $imageContents, string $format = 'jpeg'): EnhancedImage
    {
        $lighting = (bool) config('photo.enhancement.lighting.enabled', false);
        $segment = (bool) config('photo.enhancement.segmentation.enabled', false);

        try {
            $response = $this->client()
                ->attach('image', $imageContents, 'photo.'.$format)
                ->post('/preprocess-image', [
                    'lighting' => $lighting ? 'true' : 'false',
                    'segment' => $segment ? 'true' : 'false',
                    'format' => $format,
                ]);
        } catch (Throwable $e) {
            // Network/transport failure or timeout. Log metadata only — never the
            // image. The caller applies fail-open and uses the unenhanced photo.
            Log::warning('[enhance] preprocess request failed: transport error');

            throw new PhotoEnhancementException('Image enhancement request failed: transport error');
        }

        if ($response->failed()) {
            Log::warning('[enhance] preprocess rejected', ['status' => $response->status()]);

            throw new PhotoEnhancementException('Image enhancement rejected with status '.$response->status());
        }

        $body = $response->body();
        if ($body === '') {
            Log::warning('[enhance] preprocess returned an empty body');

            throw new PhotoEnhancementException('Image enhancement returned an empty body');
        }

        return new EnhancedImage(
            contents: $body,
            format: $format,
            lightingApplied: $this->headerIsTrue($response->header('X-Applied-Lighting')),
            segmentApplied: $this->headerIsTrue($response->header('X-Applied-Segment')),
        );
    }

    private function headerIsTrue(?string $value): bool
    {
        return strtolower((string) $value) === 'true';
    }

    /**
     * A client pinned to the pose service base URL, with the optional X-API-Token
     * header and the enhancement timeout (longer than pose: rembg is slower).
     */
    private function client(): PendingRequest
    {
        $request = Http::baseUrl((string) config('services.pose.url'))
            ->timeout((int) config('photo.enhancement.timeout', 30));

        $token = (string) config('services.pose.token');
        if ($token !== '') {
            $request = $request->withHeaders(['X-API-Token' => $token]);
        }

        return $request;
    }
}
