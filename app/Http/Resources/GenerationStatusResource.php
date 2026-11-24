<?php

namespace App\Http\Resources;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Public shape of a generation's status, polled by the front-end loader.
 *
 * Strict allow-list. Internal/operational columns — client_photo (the private R2
 * key), fashn_prediction_id, lead_id, ip — are NEVER serialized, so they cannot
 * leak through the status API by id enumeration or otherwise.
 *
 * - status:         queued | processing | done | failed.
 * - with_background: whether this generation runs the optional background step
 *                    (drives the loader copy honestly).
 * - loader_steps:    the REAL pipeline steps for THIS generation; the background
 *                    step is present only when with_background is true.
 * - result_urls:     present only when done — one {url, with_background} per look.
 * - message:         present only when failed — a safe, user-facing reason.
 *
 * @mixin Generation
 */
class GenerationStatusResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->status,
            'with_background' => (bool) $this->with_background,
            'loader_steps' => $this->loaderSteps(),
            // Outfit progress (chains only): total layers + which one is being tried
            // on right now, so the loader shows an honest "item N of M". Absent for a
            // single try-on. No product/photo data — just the counters.
            'outfit' => $this->when($this->isOutfit(), fn () => [
                'total' => count($this->product_ids ?? []),
                'step' => (int) ($this->outfit_step ?? 0),
            ]),
            // Only when finished successfully.
            'result_urls' => $this->when($this->status === 'done', fn () => $this->result_urls ?? []),
            // Branded share cards (feed 3:4 / story 9:16), when composed. URLs
            // are minted fresh on every poll and live ≤30 minutes — the keys
            // themselves never leave the server and no public route exists.
            'share' => $this->when(
                $this->status === 'done' && ! empty($this->share_keys),
                fn () => $this->shareUrls(),
            ),
            // Only when failed: a generic, user-facing message. We do NOT echo the
            // stored FASHN error (an internal diagnostic) to the visitor.
            'message' => $this->when($this->status === 'failed', fn () => $this->failureMessage()),
        ];
    }

    /**
     * Honest loader steps for this generation: the base steps, plus the background
     * step ONLY when the generation actually performs background change, then a
     * closing step. Composed server-side so the front-end can never promise a step
     * the pipeline will not run.
     *
     * @return list<string>
     */
    private function loaderSteps(): array
    {
        $steps = array_values((array) config('fitting_room.loader.steps', []));

        if ($this->with_background) {
            $steps[] = (string) config('fitting_room.loader.background_step');
        }

        $steps[] = (string) config('fitting_room.loader.final_step');

        // Config holds translation keys; the visitor reads them in their language.
        return array_values(array_map(
            fn (string $step) => __($step),
            array_filter($steps, fn ($step) => $step !== '' && $step !== null),
        ));
    }

    /**
     * Short-lived signed URLs for the stored share cards. Behind the same
     * signed status route as the result itself; best-effort (a storage hiccup
     * drops the card from the payload rather than failing the poll).
     *
     * @return array<string, string>
     */
    private function shareUrls(): array
    {
        $disk = (string) config('fitting_room.photo.disk');
        $minutes = min(30, max(1, (int) config('share.signed_url_minutes', 30)));

        $urls = [];

        foreach ((array) $this->share_keys as $format => $key) {
            try {
                $urls[$format] = Storage::disk($disk)
                    ->temporaryUrl((string) $key, now()->addMinutes($minutes));
            } catch (Throwable $e) {
                // skip this format
            }
        }

        return $urls;
    }

    /**
     * A safe, user-facing failure message. Deliberately generic — the internal
     * (already sanitized) error stays server-side; the visitor sees no internal
     * detail, just an actionable hint.
     */
    private function failureMessage(): string
    {
        return __('generation.failed');
    }
}
