<?php

namespace App\Jobs;

use App\Models\ColorProfile;
use App\Models\ColorProfileImage;
use App\Services\Color\ColorAnalysisClient;
use App\Services\Color\ColorAnalysisRefused;
use App\Services\Color\ColorAnalysisResult;
use App\Services\Color\ColorAnalysisUnavailable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs a stored photo through the colour engine and records what it measured.
 *
 * Asynchronous by design: the analysis is seconds of CPU work in another process
 * and, on the first job after a restart, a model load on top. It rides its own
 * queue and supervisor so an admin poking at the engine can never tie up the
 * try-on workers.
 *
 * Three outcomes, kept strictly apart, because they mean different things to
 * whoever is looking at the page:
 *
 *   ok        the engine measured the photo;
 *   rejected  the engine LOOKED and said no - too dark, no face, and so on.
 *             A normal result about the photograph, never retried: the same
 *             bytes get the same answer every time;
 *   failed    OUR side broke - the service was down, the models were missing.
 *             Nothing about the photograph, and the only thing worth retrying.
 *
 * Retries therefore happen on exactly one condition: a transport error or a 5xx,
 * which the client raises as ColorAnalysisUnavailable. Everything else fails the
 * profile on the first attempt rather than burning the budget on an answer that
 * cannot change.
 *
 * Logs carry the profile id, the status and the reason. Never a photo, never a
 * storage key, never a sample.
 */
class AnalyzeColorProfile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Three attempts, spread out. Only a transient failure ever reaches the
     * queue's retry path - everything else marks the profile and returns - so
     * the gaps are sized for "the service is restarting", not for a rate limit.
     *
     * @var int
     */
    public $tries = 3;

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60];
    }

    /**
     * @param  bool  $debug  ask the engine for its rendered views. True for the
     *                       internal instrument, where the pictures are the
     *                       point; false for a member's own analysis, where
     *                       none are shown and so none are stored.
     */
    public function __construct(public ColorProfile $profile, public bool $debug = true) {}

    public function handle(ColorAnalysisClient $client): void
    {
        $image = $this->sourceImage();
        if ($image === null) {
            // The photo is gone - swept by the retention purge while the job sat
            // in the queue, or never stored. Nothing to analyse and nothing to
            // retry; say so plainly rather than leaving the page spinning.
            $this->markFailed('The photo is no longer available.');

            return;
        }

        $contents = $this->contents($image);
        if ($contents === null) {
            $this->markFailed('The photo could not be read from storage.');

            return;
        }

        $this->profile->update(['status' => ColorProfile::STATUS_PROCESSING]);

        try {
            $result = $client->analyze($contents, $this->profile->quiz, debug: $this->debug);
        } catch (ColorAnalysisUnavailable $e) {
            // Transient, and the ONLY thing worth another go. Rethrowing hands
            // the decision to the queue, which already knows how many attempts
            // are left and how long to wait - and calls failed() when they run
            // out. Catching it here to release by hand would reimplement that,
            // badly, and silently do nothing when the job is run outside a queue.
            throw $e;
        } catch (ColorAnalysisRefused $e) {
            $this->markFailed($e->getMessage());

            return;
        }

        $this->record($result);
        $this->pin();
        $this->storeImages($result, $image);

        Log::info('[color] profile analysed', [
            'profile_id' => $this->profile->id,
            'status' => $this->profile->status,
            'reject_reason' => $this->profile->reject_reason,
        ]);
    }

    /**
     * The last attempt has been spent, or the job was killed. Stop pretending
     * and mark the profile failed, so the page stops spinning on a result that
     * is never coming.
     */
    public function failed(?Throwable $e): void
    {
        $this->markFailed($e?->getMessage() ?? 'Colour analysis did not complete.');
    }

    /**
     * Record what the engine said. The sections are stored as the engine
     * serialises them - it owns that schema, and reshaping it here would mean
     * editing this job every time it grows a field.
     */
    private function record(ColorAnalysisResult $result): void
    {
        $confidence = $result->result['confidence'] ?? null;

        $this->profile->update([
            'status' => $result->rejected()
                ? ColorProfile::STATUS_REJECTED
                : ColorProfile::STATUS_OK,
            'reject_reason' => $result->rejectReason(),
            'samples' => $result->section('samples'),
            'zones' => $result->section('zones'),
            'white_balance' => $result->section('white_balance'),
            'axes' => $result->section('axes'),
            'contrast' => $result->section('contrast'),
            // The top season as a slug, the top two with their scores beside it,
            // and which version of the classifier data decided them. All three:
            // a slug on its own is a claim with no date on it, and the engine's
            // anchor points live in a versioned file that will change.
            'season' => $result->topSeason(),
            'seasons' => $result->section('seasons'),
            'classifier' => $result->section('classifier'),
            'confidence' => is_numeric($confidence) ? (float) $confidence : null,
            'algo_version' => $result->algoVersion(),
            'target_calibrated' => $result->targetCalibrated(),
            'error' => null,
        ]);
    }

    /**
     * Make this the member's season — only when it placed one, and only for
     * the member's own analysis. A refused or unplaced retake leaves the
     * pinned season where it was; an instrument upload from /admin/color
     * (debug views, no consent stamp: it may be somebody else's face) never
     * moves it. A member's own upload carries no stamp while consent is
     * switched off (config/consent.php), so it counts as own by being no
     * instrument run.
     */
    private function pin(): void
    {
        $profile = $this->profile;

        if ($profile->status !== ColorProfile::STATUS_OK
            || $profile->rankedSeasons() === []
            || ($profile->consented_at === null && $this->debug)
            || $profile->user === null) {
            return;
        }

        $profile->user->forceFill(['color_profile_id' => $profile->id])->save();
    }

    /**
     * Park the rendered views beside the photo, under the same prefix and on the
     * same clock, so the retention sweep takes all of it together.
     */
    private function storeImages(ColorAnalysisResult $result, ColorProfileImage $source): void
    {
        foreach ($result->images as $name => $bytes) {
            $path = dirname($source->path).'/'.$name.'.png';

            try {
                Storage::disk($source->disk)->put($path, $bytes, 'private');
            } catch (Throwable $e) {
                // A view we could not store is a missing picture, not a failed
                // analysis. Never log the key.
                Log::warning('[color] could not store a debug view', [
                    'profile_id' => $this->profile->id,
                    'view' => $name,
                ]);

                continue;
            }

            ColorProfileImage::updateOrCreate(
                ['color_profile_id' => $this->profile->id, 'kind' => $name],
                ['disk' => $source->disk, 'path' => $path, 'expires_at' => $source->expires_at],
            );
        }
    }

    private function sourceImage(): ?ColorProfileImage
    {
        return $this->profile->images()
            ->where('kind', ColorProfileImage::KIND_SOURCE)
            ->first();
    }

    private function contents(ColorProfileImage $image): ?string
    {
        try {
            $contents = Storage::disk($image->disk)->get($image->path);
        } catch (Throwable $e) {
            return null;
        }

        return ($contents === null || $contents === '') ? null : $contents;
    }

    /**
     * Our environment broke. The message is for an operator reading the page -
     * it never carries anything derived from the photograph.
     */
    private function markFailed(string $message): void
    {
        $this->profile->update([
            'status' => ColorProfile::STATUS_FAILED,
            'error' => $message,
        ]);

        Log::warning('[color] profile failed', [
            'profile_id' => $this->profile->id,
        ]);
    }
}
