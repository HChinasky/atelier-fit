<?php

namespace App\Jobs;

use App\Models\Generation;
use App\Models\Product;
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
 * Tries on a SET of garments by CHAINING single try-on calls through the SAME
 * TryOnProvider the single flow uses (the engine dresses one garment per call):
 * the result of step N becomes the person image for step N+1. The provider adds
 * preserve_pose + aspect_ratio to EVERY call, so pose and frame stay consistent
 * across the layers instead of drifting — without that the chain would compound
 * artefacts. GenerateLookJob (single try-on) is untouched and remains the main flow.
 *
 * The chain dresses in the stored wearing order (base layer first; product_ids is
 * already ordered by the controller's OutfitValidator). Phase 9 ran ONCE on the
 * original photo before this job; intermediate results come straight from the
 * engine and are never re-normalized or re-cropped.
 *
 * Privacy (Этап 7): every engine output is pulled OFF the engine CDN into our
 * private R2 immediately. The final image is kept (72h lifecycle) and shown via a
 * signed URL; the intermediate layers are used (as the next signed person image)
 * and then deleted as soon as the chain finishes — the user never sees an engine
 * CDN URL. The original photo keeps its existing 72h treatment.
 *
 * Atomicity / money: tries = 1 (a half-run chain is never silently retried). If
 * step K fails, the whole generation is marked failed with a safe message and the
 * K-1 already-completed (billed) engine calls are logged for accounting — we do
 * not pretend they were free. Runs on a dedicated queue/connection whose
 * retry_after exceeds this job's timeout, so a long chain is never re-dispatched
 * mid-poll (which would double-spend). Logs never carry photo bytes or signed URLs.
 */
class GenerateOutfitJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** No retries: a partially-billed chain must never be re-run automatically. */
    public int $tries = 1;

    /**
     * Per-job timeout (seconds). Sized for N sequential poll budgets via config,
     * and kept BELOW the outfit connection's retry_after so the worker kills an
     * over-running chain before the queue could hand it to a second worker.
     */
    public int $timeout;

    public function __construct(public Generation $generation)
    {
        $this->timeout = (int) config('fitting_room.outfit.job_timeout', 1200);
    }

    public function handle(TryOnProvider $provider, ShareCardComposer $cards, AnalyticsService $analytics): void
    {
        $startedAt = microtime(true);

        $generation = $this->generation->fresh();

        if ($generation === null || in_array($generation->status, ['done', 'failed'], true)) {
            return; // already finished or gone.
        }

        // Lead gate, defence in depth (mirrors the single job): no contact, no spend.
        if ($generation->lead_id === null) {
            $this->fail($generation, 'generation requires a captured lead', 'lead_gate', 'no_lead');

            return;
        }

        $ordered = $this->orderedProducts($generation);

        if (count($ordered) < 2) {
            $this->fail($generation, 'outfit requires at least two items', 'validate', 'invalid_set');

            return;
        }

        $disk = (string) config('fitting_room.photo.disk');
        $stepTtl = (int) config('services.fashn.signed_url_ttl_minutes', 30);

        try {
            // Step 1 person image: the (already Phase-9-processed) client photo.
            $personUrl = Storage::disk($disk)->temporaryUrl($generation->client_photo, now()->addMinutes($stepTtl));
        } catch (Throwable $e) {
            $this->fail($generation, 'could not prepare the client photo for generation', 'prepare_photo', 'storage_failed');

            return;
        }

        $total = count($ordered);
        $intermediateKeys = [];
        $finalDisplayUrl = null;
        $finalKey = null;
        $finalBytes = null;

        foreach ($ordered as $i => $product) {
            $step = $i + 1;
            // Live progress for the loader ("item N of M").
            $generation->update(['status' => 'processing', 'outfit_step' => $step]);

            $status = $this->runStep($provider, $personUrl, $product);

            // Any non-completion fails the WHOLE chain (no broken partial result).
            if ($status === null) {
                $this->failChain($generation, $disk, $intermediateKeys, $step, $total, 'generation timed out while polling for a result', 'timeout');

                return;
            }
            if ($status->isFailed() || $status->outputUrls === []) {
                $this->failChain($generation, $disk, $intermediateKeys, $step, $total, $status->error ?? 'generation returned no image', 'engine_failed');

                return;
            }

            // Privacy: pull this layer's result off the engine CDN into our R2 now.
            try {
                $bytes = $provider->downloadResult($status->outputUrls[0]);
            } catch (Throwable $e) {
                $this->failChain($generation, $disk, $intermediateKeys, $step, $total, 'could not retrieve the generated image', 'storage_failed');

                return;
            }

            // The final look loses the aspect fitter's grey margins; a layer
            // in between keeps them, since it goes back to the engine as is.
            if ($step === $total) {
                $bytes = app(PadTrimmer::class)->trim($bytes);
            }

            $key = $this->storeStepResult($disk, $generation, $step, $bytes);

            if ($step === $total) {
                // Final look: kept in R2 (72h lifecycle), shown via a signed URL.
                $finalKey = $key;
                $finalBytes = $bytes;
                $finalDisplayUrl = Storage::disk($disk)->temporaryUrl(
                    $key, now()->addHours((int) config('fitting_room.photo.ttl_hours', 72))
                );
            } else {
                // Intermediate layer: feeds the next step, then gets cleaned up.
                $intermediateKeys[] = $key;
                try {
                    $personUrl = Storage::disk($disk)->temporaryUrl($key, now()->addMinutes($stepTtl));
                } catch (Throwable $e) {
                    $this->failChain($generation, $disk, $intermediateKeys, $step, $total, 'could not prepare the layered image');

                    return;
                }
            }
        }

        // Branded share cards (Этап B1): ONE card set, for the FINAL look only —
        // intermediates never get one. Best-effort, never fails the chain.
        $shareKeys = $finalBytes === null ? null : $cards->composeAndStore($generation, $finalBytes, $ordered);

        $generation->update([
            'status' => 'done',
            'outfit_step' => $total,
            'result_urls' => [['url' => $finalDisplayUrl, 'with_background' => false]],
            // The storage key behind that (expiring) signed url, so an erasure
            // request can delete the image instead of waiting the lifecycle out.
            'result_keys' => $finalKey === null ? [] : [$finalKey],
            'share_keys' => $shareKeys,
            'error' => null,
        ]);

        // Transient layers are no longer needed — delete now; the 72h R2 lifecycle
        // is the fallback if a delete fails. The original photo and the final look
        // are kept (both under their 72h lifecycle).
        $this->deleteKeys($disk, $intermediateKeys);

        $distinctId = $analytics->leadDistinctId($generation->lead_id);
        $leadIp = $generation->lead?->ip;

        $analytics->capture('generation_completed', array_filter([
            'provider' => (string) config('services.tryon.driver'),
            'mode' => 'outfit',
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            // One engine credit per layer in a chain.
            'cost_usd' => $this->costUsd($total),
        ], fn ($v) => $v !== null), $distinctId, $leadIp);

        $analytics->capture('outfit_generated', [
            'items_count' => $total,
            'categories' => array_values(array_map(static fn (Product $p): string => (string) $p->category, $ordered)),
        ], $distinctId, $leadIp);
    }

    /** Engine cost in USD from the configured per-credit rate, or null when unset. */
    private function costUsd(int $credits): ?float
    {
        $rate = config('analytics.cost_per_credit_usd');

        return $rate === null ? null : round($credits * (float) $rate, 4);
    }

    /**
     * Submit one layer and poll it to a terminal state. Returns the terminal
     * TryOnStatus (a failed one carrying the message on an API-level error), or
     * null only when polling times out.
     */
    private function runStep(TryOnProvider $provider, string $personUrl, Product $product): ?TryOnStatus
    {
        try {
            // preserve_pose + aspect_ratio are added by the provider on EVERY call,
            // so the pose and frame are held across the whole chain.
            $predictionId = $provider->run($personUrl, $product->image_flat_url, $this->options($product));

            return $this->pollUntilTerminal($provider, $predictionId);
        } catch (TryOnException $e) {
            // API-level failure (submit rejected, or a status request errored): turn
            // it into a terminal failed status so the chain ends with its reason.
            return new TryOnStatus(status: 'failed', error: $e->getMessage());
        }
    }

    /**
     * The wearing-order product list, resolved from the stored (already ordered)
     * product_ids. Preserves the stored order even though findMany does not.
     *
     * @return list<Product>
     */
    private function orderedProducts(Generation $generation): array
    {
        $ids = (array) ($generation->product_ids ?? []);
        $byId = Product::findMany($ids)->keyBy('id');

        $ordered = [];
        foreach ($ids as $id) {
            if ($byId->has($id)) {
                $ordered[] = $byId->get($id);
            }
        }

        return $ordered;
    }

    /**
     * Mark the whole chain failed with a safe message, log the spend already
     * incurred (steps 1..K-1 were billed engine calls), and clean up the useless
     * partial layers.
     */
    private function failChain(Generation $generation, string $disk, array $intermediateKeys, int $failedStep, int $total, string $reason, string $code = 'engine_failed'): void
    {
        $safe = $this->sanitizeError($reason, $generation->client_photo);

        $generation->update(['status' => 'failed', 'error' => $safe]);

        // The member paid for the WHOLE chain up front and got no final look —
        // refund it all, even though some engine steps were billed to us (that
        // burn is ours, not the user's). No-op for guests and on retries.
        app(CreditService::class)->refundForGeneration($generation);

        Log::warning('[generate-outfit] outfit chain failed', [
            'generation_id' => $generation->id,
            'failed_step' => $failedStep,
            'total_steps' => $total,
            // Layers 1..K-1 completed and were billed by the engine — accounted for,
            // not treated as free. The failed step itself may also have been billed.
            'billed_steps' => max(0, $failedStep - 1),
            'reason' => $safe,
        ]);

        $this->deleteKeys($disk, $intermediateKeys);

        $this->captureFailure($generation, 'chain_step_'.$failedStep, $code);
    }

    /**
     * Record a failed generation with a short, safe reason (pre-provider failures:
     * no lead, bad photo). Mirrors the single job's fail().
     */
    private function fail(Generation $generation, string $reason, string $stage = 'engine', string $code = 'engine_failed'): void
    {
        $safe = $this->sanitizeError($reason, $generation->client_photo);

        $generation->update(['status' => 'failed', 'error' => $safe]);

        // Same refund rule as failChain (idempotent, guest-safe).
        app(CreditService::class)->refundForGeneration($generation);

        Log::warning('[generate-outfit] generation failed', [
            'generation_id' => $generation->id,
            'reason' => $safe,
        ]);

        $this->captureFailure($generation, $stage, $code);
    }

    /** Coarse machine labels only — never the raw error text. */
    private function captureFailure(Generation $generation, string $stage, string $code): void
    {
        if ($generation->lead_id === null) {
            return;
        }

        $analytics = app(AnalyticsService::class);
        $analytics->capture('generation_failed', [
            'provider' => (string) config('services.tryon.driver'),
            'error_code' => $code,
            'stage' => $stage,
        ], $analytics->leadDistinctId($generation->lead_id), $generation->lead?->ip);
    }

    /**
     * Poll a prediction until terminal, bounded by the shared attempt budget so the
     * job can never loop forever. Returns the terminal status, or null on timeout.
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

            Sleep::for($interval)->seconds();
        }

        return null;
    }

    /**
     * Store a layer's result in private R2 under the SAME prefix/date partition as
     * the client photo, so the Phase-7 lifecycle reaps it within 72h.
     */
    private function storeStepResult(string $disk, Generation $generation, int $step, string $bytes): string
    {
        $key = sprintf('%s/%s-outfit-%d.png', dirname($generation->client_photo), Str::uuid(), $step);

        Storage::disk($disk)->put($key, $bytes, ['visibility' => 'private']);

        return $key;
    }

    /**
     * Best-effort delete of transient keys — never throws (the 72h lifecycle is the
     * safety net) and never logs key names.
     *
     * @param  list<string>  $keys
     */
    private function deleteKeys(string $disk, array $keys): void
    {
        foreach ($keys as $key) {
            try {
                Storage::disk($disk)->delete($key);
            } catch (Throwable $e) {
                // Swallow: the R2 lifecycle rule reaps anything we miss.
            }
        }
    }

    /**
     * Per-layer try-on options. Each layer is a single try-on call yielding one
     * image; the chain produces one final look. tryon-max auto-detects the garment
     * category and returns one image, so only generation_mode is forwarded. The fal
     * provider ignores these top-level keys (it reads only $options['inputs']), so
     * the same chain works verbatim on either engine.
     *
     * @return array<string, mixed>
     */
    private function options(Product $product): array
    {
        return [
            'generation_mode' => config('services.fashn.mode'),
        ];
    }

    /**
     * Redact anything secret-bearing (URLs, the photo key, signed-token params)
     * from a possibly engine-controlled message while keeping the descriptive part.
     * Same posture as the single job's sanitizeError.
     */
    private function sanitizeError(string $raw, string $photoKey): string
    {
        $clean = preg_replace('#\bhttps?://\S+#i', '[redacted-url]', $raw) ?? $raw;

        if ($photoKey !== '') {
            $clean = str_replace($photoKey, '[redacted-key]', $clean);
        }

        $clean = preg_replace(
            '#\b(x-amz-[\w-]+|signature|sig|sas|token|awsaccesskeyid)=[^\s&]+#i',
            '$1=[redacted]',
            $clean
        ) ?? $clean;

        $clean = trim($clean);

        return $clean === '' ? 'generation failed' : Str::limit($clean, 300);
    }
}
