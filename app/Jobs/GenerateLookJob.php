<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Services\Analytics\AnalyticsService;
use App\Services\Credits\CreditService;
use App\Services\Fashn\TryOnException;
use App\Services\Fashn\TryOnProvider;
use App\Services\Fashn\TryOnStatus;
use App\Services\Photo\PadTrimmer;
use App\Services\Share\ShareCardComposer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Throwable;

/**
 * Runs a virtual try-on through the TryOnProvider (FASHN) and records the result
 * on the Generation. Asynchronous by design — dispatched onto the Redis queue and
 * worked by Horizon, never run synchronously in production.
 *
 * Flow: mint a short-lived pre-signed URL for the (private) client photo →
 * provider->run() to submit (num_samples looks in one request) → poll
 * provider->status() until completed/failed or the attempt budget runs out. Two
 * failure kinds are handled distinctly:
 *   - API-level (TryOnException, no prediction id) → mark failed immediately;
 *   - runtime (status === "failed") → mark failed with the engine's error.
 *
 * Background Change (Phase 5, optional): when the generation carries
 * with_background AND the feature is enabled in config, each completed look is
 * sent through provider->changeBackground() (+1 credit per look). Its format is
 * confirmed (model_name=background-change, inputs={image,prompt}, output=[url]);
 * the step is still treated as best-effort because FASHN's model lifecycle is
 * experimental — a look whose background step fails keeps its original try-on
 * background; the generation still completes. Partial success is normal (e.g. 3
 * of 4 looks re-backgrounded).
 *
 * Every finished look is copied off the engine CDN into private R2 before it is
 * recorded, so result_urls always points at OUR storage (a signed, expiring URL)
 * and never at the vendor's CDN — results then live and die under the same 72h
 * lifecycle as the client photo.
 *
 * result_urls is always a list of {url, with_background} so each look records
 * whether the new background actually landed.
 *
 * Logs never carry photo contents or the signed URL (which holds an access token).
 */
class GenerateLookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Generation $generation) {}

    public function handle(TryOnProvider $provider, ShareCardComposer $cards, AnalyticsService $analytics): void
    {
        $startedAt = microtime(true);

        $generation = $this->generation->fresh(['product', 'lead']);

        if ($generation === null || in_array($generation->status, ['done', 'failed'], true)) {
            return; // already finished or gone — nothing to do.
        }

        // Lead gate, defence in depth: a generation must belong to a captured
        // lead. Even if something dispatched one without a lead, we refuse here
        // BEFORE any FASHN call — no contact, no spend.
        if ($generation->lead_id === null) {
            $this->fail($generation, 'generation requires a captured lead', 'lead_gate', 'no_lead');

            return;
        }

        try {
            $modelImageUrl = $this->signedClientPhotoUrl($generation);
        } catch (Throwable $e) {
            $this->fail($generation, 'could not prepare the client photo for generation', 'prepare_photo', 'storage_failed');

            return;
        }

        // 1) Submit the prediction. API-level failures land here (no id).
        try {
            $predictionId = $provider->run(
                modelImageUrl: $modelImageUrl,
                garmentImageUrl: $generation->product->image_flat_url,
                options: $this->options($generation),
            );
        } catch (TryOnException $e) {
            $this->fail($generation, $e->getMessage(), 'submit', 'api_error');

            return;
        }

        $generation->update([
            'fashn_prediction_id' => $predictionId,
            'status' => 'processing',
        ]);

        // 2) Poll the try-on until terminal or the attempt budget is exhausted.
        try {
            $status = $this->pollUntilTerminal($provider, $predictionId);
        } catch (TryOnException $e) {
            $this->fail($generation, $e->getMessage(), 'poll', 'api_error');

            return;
        }

        if ($status === null) {
            $this->fail($generation, 'generation timed out while polling for a result', 'poll', 'timeout');

            return;
        }

        if ($status->isFailed()) {
            $this->fail($generation, $status->error ?? 'generation failed', 'engine', 'engine_failed');

            return;
        }

        // 3) Try-on succeeded — N looks. Optionally re-background each (best
        //    effort), then record one entry per look with its background flag.
        $looks = $this->maybeApplyBackground($generation, $provider, $status->outputUrls);

        // 4) Privacy: pull every finished look off the engine CDN into our own
        //    private R2 before recording it. A result left on the vendor's CDN
        //    would outlive our 72h lifecycle rule and could not be deleted by us
        //    at all — the chain job already does this, and so must this one.
        $stored = $this->persistLooks($generation, $provider, $looks);

        if ($stored === null) {
            $this->fail($generation, 'could not retrieve the generated image', 'store_result', 'storage_failed');

            return;
        }

        // 5) Branded share cards (Этап B1) from the FIRST look — best-effort:
        //    the composer never throws, a null just means no Share images.
        $shareKeys = $stored['firstBytes'] === null
            ? null
            : $cards->composeAndStore($generation, $stored['firstBytes'], [$generation->product]);

        $generation->update([
            'status' => 'done',
            'result_urls' => $stored['looks'],
            // The storage keys behind those (expiring) signed urls, so an erasure
            // request can actually delete the images instead of waiting them out.
            'result_keys' => $stored['keys'],
            'share_keys' => $shareKeys,
            'error' => null,
        ]);

        $analytics->capture('generation_completed', array_filter([
            'provider' => (string) config('services.tryon.driver'),
            'mode' => 'single',
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'cost_usd' => $this->costUsd($generation->num_samples * ($generation->with_background ? 2 : 1)),
        ], fn ($v) => $v !== null), $analytics->leadDistinctId($generation->lead_id), $generation->lead?->ip);
    }

    /** Engine cost in USD from the configured per-credit rate, or null when unset. */
    private function costUsd(int $credits): ?float
    {
        $rate = config('analytics.cost_per_credit_usd');

        return $rate === null ? null : round($credits * (float) $rate, 4);
    }

    /**
     * Copy each look from the engine CDN into private R2 and swap its URL for a
     * signed R2 one, so what we hand the visitor is served from storage WE can
     * expire. Stored under the same prefix/date partition as the client photo, so
     * the 72h lifecycle rule reaps results and photos alike.
     *
     * All-or-nothing: if any look cannot be retrieved, the caller fails the
     * generation (and refunds). Falling back to the vendor URL is deliberately not
     * an option — it would silently re-open the very gap this step closes.
     *
     * @param  list<array{url: string, with_background: bool}>  $looks
     * @return array{looks: list<array{url: string, with_background: bool}>, keys: list<string>, firstBytes: string|null}|null null on failure
     */
    private function persistLooks(Generation $generation, TryOnProvider $provider, array $looks): ?array
    {
        $disk = (string) config('fitting_room.photo.disk');
        $ttlHours = (int) config('fitting_room.photo.ttl_hours', 72);

        $persisted = [];
        $keys = [];
        $firstBytes = null;

        foreach (array_values($looks) as $i => $look) {
            try {
                // Without the grey margins the aspect fitter added to the photo.
                $bytes = app(PadTrimmer::class)->trim($provider->downloadResult($look['url']));
                $firstBytes ??= $bytes;

                $key = sprintf('%s/%s-look-%d.png', dirname($generation->client_photo), Str::uuid(), $i + 1);
                Storage::disk($disk)->put($key, $bytes, ['visibility' => 'private']);

                $keys[] = $key;
                $persisted[] = [
                    'url' => Storage::disk($disk)->temporaryUrl($key, now()->addHours($ttlHours)),
                    'with_background' => $look['with_background'],
                ];
            } catch (Throwable $e) {
                // Never log the CDN url or the exception text — both can carry an
                // access token.
                Log::warning('[generate-look] could not store a look in private storage', [
                    'generation_id' => $generation->id,
                    'look' => $i + 1,
                ]);

                return null;
            }
        }

        return ['looks' => $persisted, 'keys' => $keys, 'firstBytes' => $firstBytes];
    }

    /**
     * Optionally run Background Change over each try-on look.
     *
     * Returns one {url, with_background} entry per look. The step runs only when
     * the generation carries with_background AND the feature is enabled in config
     * (double gate, so the expensive path can never switch on by accident). When
     * off, every look is returned unchanged with with_background=false — no extra
     * FASHN call, no extra credit.
     *
     * Best-effort (FASHN's model lifecycle is experimental): a failed background
     * step (API error, runtime failure, or timeout) is swallowed and the look
     * falls back to its try-on background. The generation is never failed by it;
     * partial success across looks is expected.
     *
     * @param  list<string>  $lookUrls
     * @return list<array{url: string, with_background: bool}>
     */
    private function maybeApplyBackground(Generation $generation, TryOnProvider $provider, array $lookUrls): array
    {
        $enabled = $generation->with_background && (bool) config('services.fashn.background.enabled');

        if (! $enabled || $lookUrls === []) {
            return array_map(fn (string $url) => ['url' => $url, 'with_background' => false], $lookUrls);
        }

        $prompts = (array) config('services.fashn.background.prompts');
        if ($prompts === []) {
            $prompts = ['Dubai Marina, street style'];
        }

        $looks = [];
        foreach (array_values($lookUrls) as $i => $url) {
            // Vary the scene round-robin when several prompts are configured.
            $prompt = (string) $prompts[$i % count($prompts)];
            $looks[] = $this->backgroundForLook($generation, $provider, $url, $prompt);
        }

        return $looks;
    }

    /**
     * Re-background a single look, best effort. On any failure the look keeps its
     * original try-on URL and with_background=false; the reason is logged (sanitized)
     * but never bubbles up to fail the generation.
     *
     * @return array{url: string, with_background: bool}
     */
    private function backgroundForLook(Generation $generation, TryOnProvider $provider, string $lookUrl, string $prompt): array
    {
        try {
            $predictionId = $provider->changeBackground($lookUrl, $prompt);
            $status = $this->pollUntilTerminal($provider, $predictionId);

            if ($status !== null && $status->isCompleted() && $status->outputUrls !== []) {
                return ['url' => $status->outputUrls[0], 'with_background' => true];
            }

            $reason = $status?->error ?? 'background change did not complete';
        } catch (TryOnException $e) {
            $reason = $e->getMessage();
        }

        Log::warning('[generate-look] background change failed; keeping the try-on look', [
            'generation_id' => $generation->id,
            'reason' => $this->sanitizeError($reason, $generation->client_photo),
        ]);

        return ['url' => $lookUrl, 'with_background' => false];
    }

    /**
     * Poll a prediction until it reaches a terminal state, bounded by a fixed
     * number of attempts so the job can never loop forever. Returns the terminal
     * TryOnStatus, or null if the attempt budget ran out first.
     *
     * @throws TryOnException if a status request itself fails at the API level.
     */
    private function pollUntilTerminal(TryOnProvider $provider, string $predictionId): ?TryOnStatus
    {
        $interval = (int) config('services.fashn.poll.interval_seconds', 3);
        $maxAttempts = (int) config('services.fashn.poll.max_attempts', 40);

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $status = $provider->status($predictionId);

            if ($status->isTerminal()) {
                return $status;
            }

            // Still processing — wait before the next poll (fakeable in tests).
            Sleep::for($interval)->seconds();
        }

        return null;
    }

    /**
     * Build the tryon-max try-on options. tryon-max auto-detects the garment
     * category and returns a single image, so only the quality lever
     * (generation_mode) is forwarded; the provider supplies the image fields.
     *
     * @return array<string, mixed>
     */
    private function options(Generation $generation): array
    {
        return [
            'generation_mode' => config('services.fashn.mode'),
        ];
    }

    /**
     * A short-lived pre-signed URL for the private client photo. We never make
     * the object public; FASHN gets time-bounded read access only.
     */
    private function signedClientPhotoUrl(Generation $generation): string
    {
        $disk = (string) config('fitting_room.photo.disk');
        $ttl = (int) config('services.fashn.signed_url_ttl_minutes', 30);

        return Storage::disk($disk)->temporaryUrl($generation->client_photo, now()->addMinutes($ttl));
    }

    /**
     * Record a failed generation. The message is a short reason — never private
     * data — and is also logged with the prediction id for traceability. $stage
     * and $code are coarse machine labels for analytics: which pipeline step
     * gave up and what class of failure it was — never the raw error text.
     */
    private function fail(Generation $generation, string $reason, string $stage = 'engine', string $code = 'engine_failed'): void
    {
        // The reason may be a FASHN-controlled runtime message. Sanitize it here —
        // the single point that both persists and logs the error — so a token-
        // bearing pre-signed URL or the private photo key can never leak into
        // generations.error or the logs via that untrusted channel.
        $safe = $this->sanitizeError($reason, $generation->client_photo);

        $generation->update([
            'status' => 'failed',
            'error' => $safe,
        ]);

        // The user got nothing — give a member their credit back (no-op for
        // guest generations and on retries; see CreditService).
        app(CreditService::class)->refundForGeneration($generation);

        Log::warning('[generate-look] generation failed', [
            'generation_id' => $generation->id,
            'fashn_prediction_id' => $generation->fashn_prediction_id,
            'reason' => $safe,
        ]);

        if ($generation->lead_id !== null) {
            $analytics = app(AnalyticsService::class);
            $analytics->capture('generation_failed', [
                'provider' => (string) config('services.tryon.driver'),
                'error_code' => $code,
                'stage' => $stage,
            ], $analytics->leadDistinctId($generation->lead_id), $generation->lead?->ip);
        }
    }

    /**
     * Redact anything secret-bearing from an error message while keeping the
     * descriptive part (e.g. FASHN's error name/category) for diagnostics:
     * whole URLs, the client photo key, and any lingering signed-token params
     * (X-Amz-*, Signature, SAS, token). The raw text is never persisted or
     * logged in full.
     */
    private function sanitizeError(string $raw, string $photoKey): string
    {
        // Drop any URL outright — pre-signed R2/SAS URLs carry their token here.
        $clean = preg_replace('#\bhttps?://\S+#i', '[redacted-url]', $raw) ?? $raw;

        // Redact the private photo key if it is echoed back without a scheme.
        if ($photoKey !== '') {
            $clean = str_replace($photoKey, '[redacted-key]', $clean);
        }

        // Belt-and-suspenders: redact signed-token params echoed outside a URL.
        $clean = preg_replace(
            '#\b(x-amz-[\w-]+|signature|sig|sas|token|awsaccesskeyid)=[^\s&]+#i',
            '$1=[redacted]',
            $clean
        ) ?? $clean;

        $clean = trim($clean);

        return $clean === '' ? 'generation failed' : Str::limit($clean, 300);
    }
}
