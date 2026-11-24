<?php

namespace App\Console\Commands;

use App\Services\Color\LabelReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

/**
 * The engine against the stylist, as a page somebody reads.
 *
 *     php artisan color:label-report
 *     php artisan color:label-report --out=storage/app/private/color-labels/x.html
 *
 * Writes the HTML and a `summary.json` of the same numbers beside it — the page
 * for a person, the JSON for whatever comes next.
 *
 * It computes and does not conclude. No threshold is changed by this command and
 * none is proposed: the whole point of gathering labels was that the anchor
 * points cannot be set without a human looking at where the measurements
 * actually fall, and a command that offered to set them would be guessing on
 * exactly the same evidence the guessing is meant to stop.
 *
 * Private storage, like the export: no personal data in it, which is a claim
 * about the columns rather than a licence to publish somebody's face
 * measurements.
 */
class ColorLabelReportCommand extends Command
{
    protected $signature = 'color:label-report
                            {--out= : Where to write the HTML. Defaults to a timestamped file.}
                            {--disk=local : Filesystem disk to write to.}';

    protected $description = 'Compare the engine against the stylists who labelled it';

    /**
     * Relative to the `local` disk, whose root is already storage/app/private —
     * so this lands at storage/app/private/color-labels, which is the path the
     * runbook prints. Prefixing it with `private/` again put everything in
     * storage/app/private/private, which is where the first live run went.
     */
    public const DIRECTORY = 'color-labels';

    public function handle(LabelReport $report): int
    {
        $data = $report->build();

        if ($data['all']['labelled'] === 0) {
            $this->warn('Nothing has been labelled yet, so there is nothing to compare.');

            return self::SUCCESS;
        }

        $disk = Storage::disk((string) $this->option('disk'));
        $html = $this->path();
        $json = preg_replace('/\.html$/', '', $html).'.summary.json';

        $disk->put($html, View::make('reports.color-labels', ['report' => $data])->render());
        $disk->put(
            $json,
            json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
        );

        $agreement = $data['all']['agreement'];
        $this->info(sprintf(
            '%d labels over %d profiles. Exact %s, top-2 %s, family %s.',
            $data['all']['labelled'],
            $data['all']['profiles'],
            $this->percent($agreement['exact']['rate']),
            $this->percent($agreement['within_top_2']['rate']),
            $this->percent($agreement['same_family']['rate']),
        ));
        $this->line('  '.$html);
        $this->line('  '.$json);
        $this->line('  Nothing was changed. Read it and decide.');

        return self::SUCCESS;
    }

    private function percent(?float $rate): string
    {
        return $rate === null ? '—' : round($rate * 100, 1).'%';
    }

    private function path(): string
    {
        $out = (string) $this->option('out');
        if ($out !== '') {
            return $this->relative($out);
        }

        return self::DIRECTORY.'/'.now()->format('Y-m-d\THis').'.html';
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
