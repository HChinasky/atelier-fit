<?php

namespace App\Console\Commands;

use App\Models\Generation;
use App\Services\Photo\PadTrimmer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Takes the grey aspect-fitter margins off looks stored before the try-on
 * jobs trimmed them (PadTrimmer). Rewrites each finished look's stored image
 * in place, under the same key; one that is already gone with the 72-hour
 * lifecycle is skipped. Safe to run twice: a trimmed image has no margins left.
 */
class TrimLookPaddingCommand extends Command
{
    protected $signature = 'looks:trim-padding {--dry-run : Count what would change, write nothing}';

    protected $description = 'Remove the grey padding margins from stored try-on results';

    public function handle(PadTrimmer $trimmer): int
    {
        if (! $trimmer->enabled()) {
            $this->info('Aspect fitting is off (PHOTO_OUTPUT_ASPECT empty): there is no padding to remove.');

            return self::SUCCESS;
        }

        $disk = Storage::disk((string) config('fitting_room.photo.disk'));
        $dry = (bool) $this->option('dry-run');
        $trimmed = 0;
        $unchanged = 0;
        $missing = 0;

        Generation::query()
            ->where('status', 'done')
            ->whereNotNull('result_keys')
            ->orderBy('id')
            ->each(function (Generation $generation) use ($disk, $trimmer, $dry, &$trimmed, &$unchanged, &$missing) {
                foreach ((array) $generation->result_keys as $key) {
                    try {
                        if (! is_string($key) || $key === '' || ! $disk->exists($key)) {
                            $missing++;

                            continue;
                        }

                        $bytes = (string) $disk->get($key);
                        $out = $trimmer->trim($bytes);

                        if ($out === $bytes) {
                            $unchanged++;

                            continue;
                        }

                        if (! $dry) {
                            $disk->put($key, $out, ['visibility' => 'private']);
                        }
                        $trimmed++;
                    } catch (Throwable) {
                        // Key names are not logged (they sit next to a client photo).
                        $missing++;
                    }
                }
            });

        $this->info(sprintf(
            '%s %d look(s); %d had no margins; %d gone or unreadable.',
            $dry ? 'Would trim' : 'Trimmed', $trimmed, $unchanged, $missing,
        ));

        return self::SUCCESS;
    }
}
