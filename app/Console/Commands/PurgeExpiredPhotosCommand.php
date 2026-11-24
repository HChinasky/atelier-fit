<?php

namespace App\Console\Commands;

use App\Services\Privacy\ExpiredPhotoSweeper;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Deletes stored photos and result images past the hard-delete threshold.
 *
 * Separate from app:cleanup-expired-data, and scheduled far more often, because
 * the two enforce different clocks. Database retention is measured in months, so
 * a daily sweep is ample. Photos are measured in HOURS against a published
 * 72-hour promise: run this daily and a photo uploaded just after the run would
 * not be considered again for 24 hours, blowing straight through the deadline the
 * threshold was chosen to protect. Hourly keeps the worst case at
 * threshold + 1 hour.
 */
class PurgeExpiredPhotosCommand extends Command
{
    protected $signature = 'app:purge-expired-photos
                            {--dry-run : Report what would be deleted without deleting anything}';

    protected $description = 'Delete stored photos and results past the hard-delete threshold';

    public function handle(ExpiredPhotoSweeper $sweeper): int
    {
        $hours = (int) config('privacy.photo_hard_delete_hours');
        $dryRun = (bool) $this->option('dry-run');

        if ($hours <= 0) {
            $this->warn('privacy.photo_hard_delete_hours is '.$hours.' — sweep disabled. The bucket lifecycle rule is then the ONLY deletion mechanism, and it does not guarantee the published deadline.');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('DRY RUN — nothing will be deleted.');
        }

        $result = $sweeper->sweep($hours, $dryRun);

        $this->line(sprintf('older than %dh: %d image(s)%s', $hours, $result['deleted'], $dryRun ? ' (would delete)' : ' deleted'));
        $this->line(sprintf('colour-analysis image rows past their clock: %d%s', $result['rows'], $dryRun ? ' (would delete)' : ' deleted'));

        if ($result['failed'] > 0) {
            $this->warn(sprintf('%d image(s) could not be deleted; the bucket lifecycle rule remains the backstop.', $result['failed']));
        }

        if (! $dryRun) {
            Log::info('[privacy] expired photo sweep finished', [
                'threshold_hours' => $hours,
                'deleted' => $result['deleted'],
                'failed' => $result['failed'],
                'rows' => $result['rows'],
            ]);
        }

        return self::SUCCESS;
    }
}
