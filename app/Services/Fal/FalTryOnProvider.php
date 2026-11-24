<?php

namespace App\Services\Fal;

use App\Services\Fashn\TryOnException;
use App\Services\Fashn\TryOnProvider;
use App\Services\Fashn\TryOnStatus;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * fal.ai Try-On implementation of the SAME TryOnProvider contract the job already
 * depends on (interface, TryOnStatus and TryOnException are reused unchanged from
 * App\Services\Fashn — the contract did not move). Selected over FASHN via
 * config('services.tryon.driver'); FASHN stays available behind the same seam.
 *
 * Model: fal-ai/image-apps-v2/virtual-try-on. Auth is an API key sent as the
 * "Authorization: Key <FAL_KEY>" header (no JWT). Async queue protocol, mirroring
 * what the job already does for FASHN:
 *   - run()    -> POST {base}/{model}                     submit, returns request_id
 *   - status() -> GET  {base}/{app}/requests/{id}/status  poll until COMPLETED, then
 *                 GET  {base}/{app}/requests/{id}         to read the result image
 * The queue status/result endpoints address the APP id (the model's first two path
 * segments, e.g. fal-ai/image-apps-v2), NOT the full model path — a documented fal
 * convention. Webhooks are deliberately NOT used; the job's existing poll loop
 * drives status() exactly as it did for FASHN.
 *
 * Inputs are passed as URLs: the person photo is the job's short-lived pre-signed
 * R2 URL (time-bounded read access, never made public) and the garment is the
 * catalog item URL — identical privacy posture to the FASHN path. No photo bytes,
 * signed-URL tokens or the API key ever appear in thrown messages or returned
 * errors (see sanitize()).
 *
 * Differences from FASHN, by design (do NOT break the caller):
 *   - category: image-apps-v2 auto-detects the garment; no category param is sent,
 *     so the job's catalog-category option is accepted but unused here.
 *   - num_samples: the app returns a SINGLE image, so a generation yields one look
 *     regardless of the configured num_samples. The job handles a 1-element result
 *     list normally; the budget gate's per-credit accounting is left untouched
 *     (conservative — it may reserve more than fal actually spends).
 *   - background change: not offered by this model. changeBackground() throws a
 *     TryOnException, which the job already treats as best-effort (keeps the look).
 */
class FalTryOnProvider implements TryOnProvider
{
    public function run(string $modelImageUrl, string $garmentImageUrl, array $options = []): string
    {
        // Our "person photo" -> person_image_url; our "garment" -> clothing_image_url.
        // No category/num_samples: image-apps-v2 auto-detects and returns one image.
        // preserve_pose is sent explicitly (the model defaults it to true, but we do
        // not rely on that default) to curb pose drift between the source photo and
        // the result. aspect_ratio is sent only when configured; otherwise fal uses
        // its own fashion default. Any caller-provided inputs win last.
        $payload = array_merge([
            'person_image_url' => $modelImageUrl,
            'clothing_image_url' => $garmentImageUrl,
            'preserve_pose' => (bool) config('services.fal.preserve_pose', true),
        ], $this->aspectRatio(), $options['inputs'] ?? []);

        try {
            $response = $this->client()->post($this->submitUrl(), $payload);
        } catch (Throwable $e) {
            // Transport failure — surface without leaking the payload (image URLs).
            throw new TryOnException('fal run request failed: transport error');
        }

        if ($response->failed()) {
            throw new TryOnException('fal run request rejected with status '.$response->status());
        }

        $requestId = $response->json('request_id');

        if (blank($requestId)) {
            throw new TryOnException('fal run returned no request id');
        }

        return (string) $requestId;
    }

    public function changeBackground(string $imageUrl, string $prompt, array $options = []): string
    {
        // image-apps-v2/virtual-try-on does not offer a background-change step. The
        // job only reaches here when the optional Phase 5 feature is enabled, and it
        // treats this exception as best-effort (the look keeps its original
        // background; the generation never fails because of it).
        throw new TryOnException('background change is not supported by the fal try-on provider');
    }

    public function status(string $predictionId): TryOnStatus
    {
        try {
            $response = $this->client()->get($this->statusUrl($predictionId));
        } catch (Throwable $e) {
            throw new TryOnException('fal status request failed: transport error');
        }

        if ($response->failed()) {
            throw new TryOnException('fal status request rejected with status '.$response->status());
        }

        // fal queue states are uppercase: IN_QUEUE, IN_PROGRESS, COMPLETED. Only
        // COMPLETED is terminal here; the result image lives at a separate endpoint.
        $state = strtoupper((string) $response->json('status'));

        if ($state !== 'COMPLETED') {
            // Non-terminal: report "processing" so the job keeps polling (bounded by
            // its own max-attempts, exactly as with FASHN).
            return new TryOnStatus(status: 'processing');
        }

        return $this->fetchResult($predictionId);
    }

    /**
     * Fetch the completed result and map it onto TryOnStatus. A missing image or a
     * rejected result is reported as a terminal "failed" (with a sanitized reason)
     * so the job marks the generation failed instead of polling forever; a pure
     * transport error is an API-level TryOnException, mirroring FASHN.
     */
    private function fetchResult(string $predictionId): TryOnStatus
    {
        try {
            $response = $this->client()->get($this->resultUrl($predictionId));
        } catch (Throwable $e) {
            throw new TryOnException('fal result request failed: transport error');
        }

        if ($response->failed()) {
            // A failed generation surfaces here (e.g. 422 with a detail body). Treat
            // it as a runtime failure, not an API error, so polling stops cleanly.
            return new TryOnStatus(
                status: 'failed',
                error: $this->errorMessage($response->json())
                    ?? 'fal result request rejected with status '.$response->status(),
            );
        }

        // Output shape: { image: { url } }; tolerate { images: [ { url } ] } too.
        $url = $response->json('image.url') ?? $response->json('images.0.url');

        if (filled($url)) {
            return new TryOnStatus(status: 'completed', outputUrls: [(string) $url]);
        }

        return new TryOnStatus(
            status: 'failed',
            error: $this->errorMessage($response->json()) ?? 'fal result is missing an image url',
        );
    }

    /**
     * Fetch a finished result. When the media is private (the default), the CDN
     * refuses the API key itself: the key has to be exchanged for a short-lived
     * CDN token first, which is then sent as a bearer credential. Public media
     * needs none of that, so the exchange is skipped.
     *
     * A failed exchange is not fatal on its own — we still try the plain fetch,
     * because a public-media configuration must keep working if fal ever changes
     * the token endpoint.
     */
    public function downloadResult(string $url): string
    {
        $request = Http::timeout((int) config('services.fal.timeout', 30));

        if ((bool) config('services.fal.private_media', true)) {
            $token = $this->cdnToken();

            if ($token !== null) {
                $request = $request->withToken($token);
            }
        }

        try {
            $response = $request->get($url);
        } catch (Throwable $e) {
            throw new TryOnException('fal result download failed: transport error');
        }

        if ($response->failed()) {
            throw new TryOnException('fal result download rejected with status '.$response->status());
        }

        return $response->body();
    }

    /**
     * Exchange the API key for a CDN token that identifies us as the file's owner.
     * Returns null when the exchange fails, leaving the caller to attempt an
     * unauthenticated fetch rather than failing outright.
     */
    private function cdnToken(): ?string
    {
        $endpoint = (string) config('services.fal.cdn_token_url');

        if ($endpoint === '') {
            return null;
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'Key '.(string) config('services.fal.key')])
                ->acceptJson()
                ->timeout((int) config('services.fal.timeout', 30))
                ->post($endpoint);
        } catch (Throwable $e) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        $token = $response->json('token') ?? $response->json('access_token');

        return blank($token) ? null : (string) $token;
    }

    /**
     * An API-key-authenticated JSON client. The key goes in "Authorization: Key
     * <FAL_KEY>" (fal's scheme), never logged.
     *
     * Every call also carries the two storage headers, because they are only
     * honoured per request — there is no account-wide setting. Sending them on
     * status/result calls as well is harmless and means a new call site cannot
     * quietly opt out of them.
     */
    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'Authorization' => 'Key '.(string) config('services.fal.key'),
            ...$this->storageHeaders(),
        ])
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('services.fal.timeout', 30));
    }

    /**
     * Headers that decide what fal keeps and who may read it.
     *
     * X-Fal-Store-IO: 0 stops fal storing the JSON payloads, which carry the
     * signed url of the person's photo — a 30-day copy of that url by default.
     * X-Fal-Object-Lifecycle-Preference expires the generated media and, unless
     * disabled, makes it readable only by this account instead of by anyone with
     * the link.
     *
     * @return array<string, string>
     */
    private function storageHeaders(): array
    {
        $headers = [
            'X-Fal-Store-IO' => config('services.fal.store_io', false) ? '1' : '0',
        ];

        $lifecycle = [];

        $ttl = (int) config('services.fal.media_ttl_seconds', 0);
        if ($ttl > 0) {
            $lifecycle['expiration_duration_seconds'] = $ttl;
        }

        if ((bool) config('services.fal.private_media', true)) {
            // "forbid" answers 403 to everyone else; the owning account keeps
            // access through the token exchange in downloadResult().
            $lifecycle['initial_acl'] = ['default' => 'forbid'];
        }

        if ($lifecycle !== []) {
            $headers['X-Fal-Object-Lifecycle-Preference'] = json_encode($lifecycle, JSON_UNESCAPED_SLASHES);
        }

        return $headers;
    }

    /**
     * The aspect_ratio input, as a single-key array so it can be spread into the
     * payload — present only when explicitly configured. Empty (the default) means
     * "let fal use its own fashion default", so nothing is sent.
     *
     * @return array<string, string>
     */
    private function aspectRatio(): array
    {
        $ratio = trim((string) config('services.fal.aspect_ratio', ''));

        return $ratio === '' ? [] : ['aspect_ratio' => $ratio];
    }

    private function submitUrl(): string
    {
        return $this->baseUrl().'/'.$this->model();
    }

    private function statusUrl(string $predictionId): string
    {
        return $this->baseUrl().'/'.$this->appId().'/requests/'.$predictionId.'/status';
    }

    private function resultUrl(string $predictionId): string
    {
        return $this->baseUrl().'/'.$this->appId().'/requests/'.$predictionId;
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.fal.base_url', 'https://queue.fal.run'), '/');
    }

    private function model(): string
    {
        return trim((string) config('services.fal.model', 'fal-ai/image-apps-v2/virtual-try-on'), '/');
    }

    /**
     * The queue status/result endpoints address the APP id — the model's first two
     * path segments (fal-ai/image-apps-v2), not the full model path. Configurable
     * (services.fal.app_id) in case fal changes the convention; derived otherwise.
     */
    private function appId(): string
    {
        $configured = (string) config('services.fal.app_id');
        if ($configured !== '') {
            return trim($configured, '/');
        }

        return implode('/', array_slice(explode('/', $this->model()), 0, 2));
    }

    /**
     * Pull a short, human-readable reason out of a fal error/result body, then
     * SANITIZE it — fal-controlled text is untrusted, so any URL (pre-signed photo
     * URLs carry a token) or token param is redacted before it can reach a log or
     * the persisted generations.error. Returns null when there is no error text.
     */
    private function errorMessage(mixed $body): ?string
    {
        if (! is_array($body)) {
            return null;
        }

        // FastAPI-style { detail: "..." } or { detail: [ { msg } ] }; also { error }.
        $detail = $body['detail'] ?? $body['error'] ?? null;

        if (is_array($detail)) {
            $detail = $detail['message'] ?? $detail['msg'] ?? ($detail[0]['msg'] ?? null);
        }

        if (blank($detail)) {
            return null;
        }

        return $this->sanitize((string) $detail);
    }

    /**
     * Redact anything secret-bearing from an untrusted message while keeping the
     * descriptive part: whole URLs (pre-signed R2/fal URLs carry tokens) and any
     * lingering signed-token params (X-Amz-*, Signature, SAS, token, key). Mirrors
     * the job's own sanitizeError so the provider is safe on its own too.
     */
    private function sanitize(string $raw): string
    {
        $clean = preg_replace('#\bhttps?://\S+#i', '[redacted-url]', $raw) ?? $raw;

        $clean = preg_replace(
            '#\b(x-amz-[\w-]+|signature|sig|sas|token|key|awsaccesskeyid)=[^\s&]+#i',
            '$1=[redacted]',
            $clean
        ) ?? $clean;

        $clean = trim($clean);

        return $clean === '' ? 'fal generation failed' : Str::limit($clean, 300);
    }
}
