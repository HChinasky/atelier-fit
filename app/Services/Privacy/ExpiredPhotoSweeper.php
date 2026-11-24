<?php

namespace App\Services\Privacy;

use App\Models\ColorProfileImage;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Deletes stored images once they are older than the hard-delete threshold, using
 * the storage API — the application doing the deleting, not the bucket lifecycle
 * rule doing it eventually.
 *
 * The distinction matters. A lifecycle rule is best-effort housekeeping: object
 * stores expire objects "within 24 hours of expiry, sometimes longer", so a 72h
 * rule can delete at 80-something hours while the privacy policy promises 72. The
 * rule stays as a backstop; this sweep is what keeps the promise, and it runs on a
 * threshold set BELOW 72h (config/privacy.php) so there is room for a missed run.
 *
 * It works off the storage listing rather than the database on purpose: a photo
 * uploaded by someone who then abandoned the flow has no generation row at all,
 * and a DB-driven sweep would walk straight past it. Age comes from the object's
 * own last-modified time, so nothing depends on a row surviving.
 *
 * Failures are counted, never thrown: one unreadable object must not stop the
 * sweep from clearing the rest.
 */
class ExpiredPhotoSweeper
{
    private int $deleted = 0;

    private int $failed = 0;

    /**
     * Delete every object under the photo prefix older than $hours.
     *
     * @param  bool  $dryRun  count what would go, delete nothing
     * @return array{deleted: int, failed: int, rows: int}
     */
    public function sweep(int $hours, bool $dryRun = false): array
    {
        $this->deleted = 0;
        $this->failed = 0;

        $disk = (string) config('fitting_room.photo.disk');
        $prefix = (string) config('fitting_room.photo.prefix');
        $cutoff = now()->subHours($hours)->getTimestamp();

        foreach (Storage::disk($disk)->allFiles($prefix) as $path) {
            try {
                if (Storage::disk($disk)->lastModified($path) >= $cutoff) {
                    continue;
                }

                if (! $dryRun) {
                    Storage::disk($disk)->delete($path);
                }

                $this->deleted++;
            } catch (Throwable $e) {
                // Never log the key — it identifies a private photo. The count is
                // what the operator needs; the lifecycle rule covers the remainder.
                $this->failed++;
            }
        }

        return [
            'deleted' => $this->deleted,
            'failed' => $this->failed,
            'rows' => $this->forgetExpiredRows($dryRun),
        ];
    }

    /**
     * Drop the rows that pointed at objects this sweep has now removed.
     *
     * The storage pass above is deliberately listing-driven and knows nothing
     * about the database, because a photo whose owner abandoned the flow has no
     * row at all. Colour-analysis images DO have rows — that is how an authorised
     * controller finds them without a public URL — and a row outliving its object
     * is a dead link the page would try to render. Clearing them here keeps the
     * profile carrying only what was measured, which is what the privacy policy
     * promises.
     *
     * Clock, not listing: the row's own expires_at. An object already gone for
     * any reason (a lifecycle rule got there first, someone emptied the bucket)
     * must still take its row with it.
     */
    private function forgetExpiredRows(bool $dryRun): int
    {
        $query = ColorProfileImage::query()->where('expires_at', '<=', now());

        if ($dryRun) {
            return $query->count();
        }

        return $query->delete();
    }
}
