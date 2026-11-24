<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreOutfitGenerationRequest;
use App\Jobs\GenerateOutfitJob;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\Lead;
use App\Models\Product;
use App\Services\Analytics\AnalyticsService;
use App\Services\Credits\CreditService;
use App\Services\Generation\GenerationGate;
use App\Services\Generation\LeadToken;
use App\Services\Outfit\OutfitValidator;
use App\Services\Photo\PhotoPreprocessor;
use App\Services\Photo\PhotoPreprocessResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Queues an OUTFIT generation: several garments tried on in sequence on one
 * person (a chain of single try-on calls). Sibling of GenerationController — the
 * single-garment endpoint is untouched and stays the main flow.
 *
 * Gate order, all BEFORE any engine call so a bad request never spends:
 *   lead (request) → set composition (OutfitValidator) → photo (Phase 9, ONCE) →
 *   budget (the set counts as N generations, reserved atomically).
 */
class OutfitGenerationController extends Controller
{
    /**
     * POST /api/fitting-room/generate-outfit
     */
    public function store(
        StoreOutfitGenerationRequest $request,
        OutfitValidator $validator,
        GenerationGate $gate,
        PhotoPreprocessor $preprocessor,
        LeadToken $leadToken,
        AnalyticsService $analytics,
    ): JsonResponse {
        $validated = $request->validated();

        $lead = Lead::findOrFail($validated['lead_id']);

        // Ownership gate for member leads — same rule and reason as the
        // single endpoint (member generations debit the owner's credits).
        if (! $leadToken->verify($lead, $validated['lead_token'] ?? null)) {
            return response()->json([
                'status' => 'error',
                'message' => __('generation.lead_missing'),
            ], 403);
        }

        $products = Product::findMany($validated['product_ids']);

        // 1) Set composition: dress-solo, no duplicate layer, known layers, size.
        //    Invalid → 422 with a localized reason, no engine call, no spend.
        $plan = $validator->validate($products);

        if (! $plan->valid) {
            return response()->json([
                'status' => 'error',
                'reason' => $plan->reason->value,
                'message' => __($plan->reason->messageKey()),
            ], 422);
        }

        // 2) Photo gate — Phase 9 runs ONCE on the original photo, with the set's
        //    representative (bottom-most) category so a set that reaches the legs is
        //    validated/cropped for full height. Intermediates are never reprocessed.
        $disk = (string) config('fitting_room.photo.disk');
        $photoKey = $validated['photo_key'];

        try {
            $original = Storage::disk($disk)->get($photoKey) ?? '';
        } catch (\Throwable $e) {
            $original = '';
        }

        $preprocess = $preprocessor->process($original, $plan->representativeCategory());

        $distinctId = $analytics->leadDistinctId($lead->id);

        if ($preprocess->isRejected()) {
            $analytics->capture('photo_rejected', [
                'reason' => $preprocess->reason?->value ?? 'other',
            ], $distinctId);

            return response()->json([
                'status' => 'error',
                'reason' => $preprocess->reason->value,
                'message' => __($preprocess->reason->messageKey()),
            ], 422);
        }

        if ($preprocess->isUnavailable()) {
            return response()->json([
                'status' => 'error',
                'message' => __('generation.photo_service_down'),
            ], 503);
        }

        $cropBox = $preprocess->cropBox?->toArray();

        // Same shape as the single endpoint — see the note there about the
        // segment/lighting flags reflecting configuration.
        $analytics->capture('photo_accepted', [
            'crop_applied' => $cropBox !== null,
            'preprocess_segment' => (bool) config('photo.enhancement.segmentation.enabled'),
            'preprocess_lighting' => (bool) config('photo.enhancement.lighting.enabled'),
        ], $distinctId);

        // 3) Budget: a set of N garments = N engine calls = N generations. Reserve
        //    ALL N up front (atomic) against BOTH the global cap and the per-user
        //    daily limit — if they do not all fit, the chain never starts.
        $count = count($plan->products);
        $result = $gate->attempt($lead, (string) $request->ip(), cost: $count, units: $count);

        if (! $result->allowed) {
            $analytics->capture('budget_gate_blocked', [
                'scope' => $result->status === 503 ? 'global' : 'user',
            ], $distinctId);

            return response()->json(array_filter([
                'status' => 'error',
                'message' => $result->message,
                // Funnel signal for the front-end CTA, absent otherwise.
                'code' => $result->code,
            ]), $result->status);
        }

        // Only once the chain will run — see the single endpoint: storing it
        // before the gate deleted the photo on every refusal.
        if ($preprocess->isProcessed()) {
            $photoKey = $this->storeProcessedPhoto($disk, $photoKey, $preprocess);
        }

        $orderedIds = array_map(static fn (Product $p): int => $p->id, $plan->products);

        $generation = Generation::create([
            'lead_id' => $lead->id,
            // Base layer, so the FK / any single-product read resolves to a real row.
            'product_id' => $orderedIds[0],
            // The ordered set — its presence marks this generation as an outfit.
            'product_ids' => $orderedIds,
            'client_photo' => $photoKey,
            // The upload it was processed from: kept for the next look on the
            // same photo, and erased with this row.
            'source_photo' => $validated['photo_key'],
            'status' => 'queued',
            // The chain yields ONE final look (the engine returns one image per call).
            'num_samples' => 1,
            'with_background' => false,
            'outfit_step' => 0,
            'expires_at' => now()->addHours((int) config('fitting_room.photo.ttl_hours')),
        ]);

        // Member spend happened inside the gate — tie it to the generation so
        // a failed chain can find it to refund.
        if ($result->creditTransactionId !== null) {
            app(CreditService::class)->attachGeneration(
                CreditTransaction::findOrFail($result->creditTransactionId),
                $generation,
            );
        }

        GenerateOutfitJob::dispatch($generation)
            ->onConnection((string) config('fitting_room.outfit.queue_connection'))
            ->onQueue((string) config('fitting_room.outfit.queue'));

        $analytics->capture('generation_started', [
            'provider' => (string) config('services.tryon.driver'),
            'mode' => 'outfit',
            'items_count' => $count,
        ], $distinctId);

        return response()->json([
            'status' => 'queued',
            'generation_id' => $generation->id,
            // Crop region (or null) for the Before/After slider — geometry only.
            'crop_box' => $cropBox,
            // Signed URL of the photo the chain actually starts from — the PROCESSED
            // image (oriented, lit, segmented, cropped), or the original on a fail-open
            // skip. Shown as "До" so it matches "Після". Same rationale as the single
            // endpoint: the visitor's own photo behind a TTL-bound signed link.
            'before_url' => $this->beforeImageUrl($disk, $photoKey),
            // Total layers, so the loader can show an honest "item N of M".
            'outfit' => ['total' => $count],
            'status_url' => URL::temporarySignedRoute(
                'fitting-room.generation.status',
                now()->addHours((int) config('fitting_room.photo.ttl_hours')),
                ['generation' => $generation->id],
            ),
        ], 202);
    }

    /**
     * Persist the processed photo next to the upload, which is kept for further
     * looks (same behaviour and rationale as the single endpoint).
     */
    private function storeProcessedPhoto(string $disk, string $originalKey, PhotoPreprocessResult $preprocess): string
    {
        $processedKey = sprintf('%s/%s.%s', dirname($originalKey), Str::uuid(), $preprocess->format);

        Storage::disk($disk)->put($processedKey, (string) $preprocess->contents, ['visibility' => 'private']);

        return $processedKey;
    }

    /**
     * A short-lived signed URL of the (processed) client photo for the Before/After
     * slider. Best-effort: a storage hiccup returns null and the slider degrades
     * gracefully — it must never 500 the submit.
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
