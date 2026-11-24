<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGenerationRequest;
use App\Jobs\GenerateLookJob;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\Lead;
use App\Models\Product;
use App\Services\Analytics\AnalyticsService;
use App\Services\Credits\CreditService;
use App\Services\Generation\GenerationGate;
use App\Services\Generation\LeadToken;
use App\Services\Photo\PhotoPreprocessor;
use App\Services\Photo\PhotoPreprocessResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Thin entry point that queues a try-on generation (Phase 4). No UI / result
 * screen here (Phase 6) — this exists to hang the budget gates on. Every gate
 * runs BEFORE the job is dispatched, so a rejected request never spends a FASHN
 * credit.
 */
class GenerationController extends Controller
{
    /**
     * POST /api/fitting-room/generate
     */
    public function store(StoreGenerationRequest $request, GenerationGate $gate, PhotoPreprocessor $preprocessor, LeadToken $leadToken, AnalyticsService $analytics): JsonResponse
    {
        $validated = $request->validated();

        // Lead gate already enforced by validation (lead_id required + exists).
        $lead = Lead::findOrFail($validated['lead_id']);

        // Ownership gate: a MEMBER lead debits its owner's credits, so the id
        // alone must not be enough (ids enumerate). Checked before any work.
        if (! $leadToken->verify($lead, $validated['lead_token'] ?? null)) {
            return response()->json([
                'status' => 'error',
                'message' => __('generation.lead_missing'),
            ], 403);
        }

        $product = Product::findOrFail($validated['product_id']);

        // Photo gate (Phase 9.5): preprocess BEFORE the budget gate, synchronously,
        // so an unsuitable photo gets immediate feedback and never spends a FASHN
        // credit. Order of gates: lead → photo → budget cap. The CATALOG category
        // (upper_body/lower_body/dresses) drives validation+cropping, NOT the
        // FASHN-mapped one. On success the processed copy is what the engine gets
        // (the upload is kept for further looks); on a fail-open skip the
        // original is used unchanged.
        $disk = (string) config('fitting_room.photo.disk');
        $photoKey = $validated['photo_key'];

        try {
            $original = Storage::disk($disk)->get($photoKey) ?? '';
        } catch (\Throwable $e) {
            // Unreadable / missing object: treat as no bytes. Preprocessing then
            // degrades per the failure mode (skip on fail-open) instead of 500-ing.
            $original = '';
        }

        $preprocess = $preprocessor->process($original, $product->category);

        $distinctId = $analytics->leadDistinctId($lead->id);

        if ($preprocess->isRejected()) {
            // Machine reason code only (PhotoValidator's enum value) — never
            // the localized message and never any image data.
            $analytics->capture('photo_rejected', [
                'reason' => $preprocess->reason?->value ?? 'other',
            ], $distinctId);

            return response()->json([
                'status' => 'error',
                'reason' => $preprocess->reason->value,
                // The suitability hint, in the visitor's language.
                'message' => __($preprocess->reason->messageKey()),
            ], 422);
        }

        if ($preprocess->isUnavailable()) {
            return response()->json([
                'status' => 'error',
                'message' => __('generation.photo_service_down'),
            ], 503);
        }

        // The crop region (fractions of the photo) the cropper framed, if any. Null
        // on a fail-open skip or the uncropped fallback. Returned to the client so
        // the Before/After slider can show "Before" framed to the same region.
        $cropBox = $preprocess->cropBox?->toArray();

        // The photo passed the gate (processed, or fail-open skipped). The
        // segment/lighting flags reflect the CONFIGURED preprocessing of this
        // deployment — the per-photo applied/fallback detail is not carried
        // out of the preprocessor.
        $analytics->capture('photo_accepted', [
            'crop_applied' => $cropBox !== null,
            'preprocess_segment' => (bool) config('photo.enhancement.segmentation.enabled'),
            'preprocess_lighting' => (bool) config('photo.enhancement.lighting.enabled'),
        ], $distinctId);

        // Generation shape comes from config, never hardcoded: look count (clamped
        // to FASHN's 1–4) and whether the optional background step runs. Both
        // default modest (2 looks, background off) so the costly path can't switch
        // on by accident.
        $numSamples = max(1, min(4, (int) config('services.fashn.num_samples')));
        $withBackground = (bool) config('services.fashn.background.enabled');

        // Real FASHN cost in credits: one per look, doubled when each look also
        // gets a background change (up to 8 for 4 looks + background). The gate
        // consumes this much of the global cap — not a flat "1 per generation".
        $cost = $numSamples * ($withBackground ? 2 : 1);

        // Global cap + per-user flood guard — before any dispatch / provider call.
        $result = $gate->attempt($lead, (string) $request->ip(), $cost);

        if (! $result->allowed) {
            // 503 is the global engine-credit cap; everything else (429 flood
            // guard, 403 free allowance, 402 member credits) is per-user.
            $analytics->capture('budget_gate_blocked', [
                'scope' => $result->status === 503 ? 'global' : 'user',
            ], $distinctId);

            return response()->json(array_filter([
                'status' => 'error',
                'message' => $result->message,
                // Machine-readable funnel signal (signup_required /
                // credits_exhausted) — drives the front-end CTA, absent otherwise.
                'code' => $result->code,
            ]), $result->status);
        }

        // Only now that the generation will run: store the processed photo
        // (and drop the raw upload). Doing it before the gate destroyed the
        // member's photo on every refusal (paywall, flood guard, global cap)
        // while the page still held its key, so the next try failed.
        if ($preprocess->isProcessed()) {
            $photoKey = $this->storeProcessedPhoto($disk, $photoKey, $preprocess);
        }

        $generation = Generation::create([
            'lead_id' => $lead->id,
            'product_id' => $product->id,
            'client_photo' => $photoKey,
            // The upload it was processed from: kept for the next look on the
            // same photo, and erased with this row.
            'source_photo' => $validated['photo_key'],
            'status' => 'queued',
            'num_samples' => $numSamples,
            'with_background' => $withBackground,
            'expires_at' => now()->addHours((int) config('fitting_room.photo.ttl_hours')),
        ]);

        // Member spend happened inside the gate (before this row existed) —
        // tie it to the generation now so a failed job can find it to refund.
        if ($result->creditTransactionId !== null) {
            app(CreditService::class)->attachGeneration(
                CreditTransaction::findOrFail($result->creditTransactionId),
                $generation,
            );
        }

        GenerateLookJob::dispatch($generation);

        $analytics->capture('generation_started', [
            'provider' => (string) config('services.tryon.driver'),
            'mode' => 'single',
            'items_count' => 1,
        ], $distinctId);

        return response()->json([
            'status' => 'queued',
            'generation_id' => $generation->id,
            'with_background' => $generation->with_background,
            // Crop region (or null) for the Before/After slider — geometry only, no
            // image content, so it is safe to expose.
            'crop_box' => $cropBox,
            // Short-lived signed URL of the photo the engine actually receives — the
            // PROCESSED image (oriented, lit, segmented, cropped) when preprocessing
            // ran, or the original on a fail-open skip. The Before/After slider shows
            // THIS as "До" so it matches "Після" (same framing, same input), instead
            // of the raw upload which now looks nothing like the engine input. It is
            // the visitor's own photo behind a TTL-bound signed link, not a public URL.
            'before_url' => $this->beforeImageUrl($disk, $photoKey),
            // Signed polling URL: the front-end's only way to read this
            // generation's status. The HMAC covers this id, so the link can't be
            // edited to read another generation. Valid as long as the generation
            // lives (its photo TTL), which comfortably outlasts polling.
            'status_url' => URL::temporarySignedRoute(
                'fitting-room.generation.status',
                now()->addHours((int) config('fitting_room.photo.ttl_hours')),
                ['generation' => $generation->id],
            ),
        ], 202);
    }

    /**
     * Persist the processed photo under a new key in the SAME prefix/date
     * partition as the upload (so the sweep still finds it), with the processed
     * format's extension. The upload itself stays: the next look on this photo
     * is processed from it for its own category, and it is recorded on the
     * generation (source_photo) so erasure deletes it with the row. Both go with
     * the 72-hour sweep at the latest.
     */
    private function storeProcessedPhoto(string $disk, string $originalKey, PhotoPreprocessResult $preprocess): string
    {
        $processedKey = sprintf('%s/%s.%s', dirname($originalKey), Str::uuid(), $preprocess->format);

        Storage::disk($disk)->put($processedKey, (string) $preprocess->contents, ['visibility' => 'private']);

        return $processedKey;
    }

    /**
     * A short-lived signed URL of the (processed) client photo for the Before/After
     * slider. Best-effort: the "before" image is a nicety, so a storage hiccup
     * returns null and the slider degrades gracefully — it must never 500 the submit.
     */
    private function beforeImageUrl(string $disk, string $key): ?string
    {
        try {
            return Storage::disk($disk)->temporaryUrl(
                $key,
                now()->addHours((int) config('fitting_room.photo.ttl_hours')),
            );
        } catch (\Throwable $e) {
            return null;
        }
    }
}
