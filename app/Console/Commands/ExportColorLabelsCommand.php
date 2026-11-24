<?php

namespace App\Console\Commands;

use App\Models\ColorProfile;
use App\Models\ColorProfileLabel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Write the labelled profiles out as a calibration dataset.
 *
 *     php artisan color:export-labels
 *     php artisan color:export-labels --out=storage/app/private/color-labels/x.json
 *
 * **This is a file of numbers, not a file of people.** Every row is a set of
 * measurements beside a verdict, and the point of it is to be opened in a
 * notebook and argued with — which is exactly why it must be safe to copy onto
 * a laptop. So it carries no user id, no phone number, no name, no filename and
 * no storage path. The one identifier that survives is `person_ref`, and only
 * because it is `p001`: whoever holds the sheet of paper next to the camera can
 * resolve it and the file cannot.
 *
 * The exclusions are a whitelist, not a blacklist — {@see self::row()} builds
 * the row field by field rather than taking a model and removing things from
 * it. A blacklist leaks every column added after it was written.
 *
 * Private storage, because "no personal data" is a claim about the columns and
 * not a licence to leave a file of somebody's face measurements in a public
 * bucket.
 */
class ExportColorLabelsCommand extends Command
{
    protected $signature = 'color:export-labels
                            {--out= : Where to write it. Defaults to a timestamped file.}
                            {--disk=local : Filesystem disk to write to.}';

    protected $description = 'Export labelled colour profiles as a calibration dataset';

    /**
     * Relative to the `local` disk, whose root is already storage/app/private —
     * so this lands at storage/app/private/color-labels, which is the path the
     * runbook prints. Prefixing it with `private/` again put everything in
     * storage/app/private/private, which is where the first live run went.
     */
    public const DIRECTORY = 'color-labels';

    public function handle(): int
    {
        $labels = ColorProfileLabel::query()
            ->with('profile')
            ->orderBy('color_profile_id')
            ->orderBy('user_id')
            ->get()
            ->filter(fn (ColorProfileLabel $label) => $label->profile !== null);

        if ($labels->isEmpty()) {
            $this->warn('Nothing has been labelled yet.');

            return self::SUCCESS;
        }

        $document = [
            'exported_at' => now()->toIso8601String(),
            'rows' => $labels->count(),
            'profiles' => $labels->pluck('color_profile_id')->unique()->count(),
            'contains_personal_data' => false,
            'note' => 'Measurements and verdicts only. No account, no contact details, no '
                .'filenames, no storage paths. person_ref is a handle from the shoot sheet, '
                .'not a name.',
            'labels' => $labels->map(fn (ColorProfileLabel $label) => $this->row($label))->values(),
        ];

        $path = $this->path();
        Storage::disk((string) $this->option('disk'))->put(
            $path,
            json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );

        $this->info(sprintf(
            'Wrote %d labels over %d profiles to %s (disk: %s).',
            $document['rows'],
            $document['profiles'],
            $path,
            $this->option('disk'),
        ));

        return self::SUCCESS;
    }

    /**
     * One row: everything the engine measured, what it concluded, and what a
     * person said.
     *
     * Built field by field on purpose. Handing out `$profile->toArray()` minus a
     * few keys would export user_id the day somebody adds a column.
     *
     * @return array<string, mixed>
     */
    private function row(ColorProfileLabel $label): array
    {
        /** @var ColorProfile $profile */
        $profile = $label->profile;
        $classifier = $profile->classifier ?? [];

        return [
            // The profile id, so a row can be traced back through the admin
            // page while the profile still exists. An internal counter, not an
            // identifier of a person.
            'profile_id' => $profile->id,
            'person_ref' => $profile->person_ref,
            'lighting' => $profile->lighting,

            // What the engine measured.
            'samples' => $profile->samples,
            'zones' => $profile->zones,
            'axes' => $profile->axes,
            'contrast' => $profile->contrast,
            'white_balance' => $profile->white_balance,
            'quiz' => $profile->quiz,

            // What it concluded, and which version concluded it. Without the
            // versions a row is a claim with no date on it: the anchor points
            // live in a file that changes, and two rows saying deep_winter six
            // months apart may have been decided by different numbers.
            'engine' => [
                'seasons' => $profile->seasons,
                'season' => $profile->season,
                'confidence' => $profile->confidence,
                'low_confidence' => $profile->hasLowConfidence(),
                'algo_version' => $profile->algo_version,
                'classifier_version' => $classifier['version'] ?? null,
                'target_calibrated' => (bool) $profile->target_calibrated,
            ],

            // And what a person said. No user id — see the class docstring.
            'stylist' => $label->asDataset(),
        ];
    }

    private function path(): string
    {
        $out = (string) $this->option('out');
        if ($out !== '') {
            // Accept the documented full path as well as a bare one: the
            // command line in the runbook says storage/app/..., and a disk
            // wants the part after that.
            return $this->relative($out);
        }

        return self::DIRECTORY.'/'.now()->format('Y-m-d\THis').'.json';
    }

    /**
     * Turn the path the runbook prints into one the disk understands.
     *
     * The command line in the runbook is the real filesystem path, and the disk
     * is rooted part-way down it, so both prefixes have to come off.
     */
    private function relative(string $out): string
    {
        return preg_replace('#^storage/app/(private/)?#', '', $out) ?? $out;
    }
}
