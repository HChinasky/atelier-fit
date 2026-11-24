<?php

namespace App\Console\Commands;

use App\Models\ColorProfile;
use App\Services\Color\ColorAnalysisClient;
use App\Services\Color\ColorAnalysisRefused;
use App\Services\Color\ColorAnalysisUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * What today's thresholds would say about the faces already measured.
 *
 *     php artisan color:rule-check
 *     php artisan color:rule-check --profile=18
 *     php artisan color:rule-check --json
 *
 * Every threshold the engine judges a zone on is marked PRELIMINARY, and the
 * only honest way to move one is to see what it would do to the faces already
 * collected. Re-running them is not an option: the photographs go on a 60-hour
 * clock and the oldest profiles have had none for months. What is left is the
 * numbers — which is enough, now that a rejected zone keeps them.
 *
 * So: change a threshold, run this, and read what would have changed on faces
 * that are already in the table. Nothing is written and nothing is re-measured.
 *
 * **The rules are not re-implemented here.** They are the engine's, reached over
 * /color-rule-check, because a second copy of them in PHP would drift from the
 * one that decides real profiles, and a rule-checker that disagrees with the
 * engine tells an operator about itself and nothing else. The trade is that the
 * pose service has to be reachable; it says so plainly when it is not.
 *
 * Two things this cannot answer, and it says which zones they touch rather than
 * quietly calling them fine:
 *
 *   - a rejection decided on PIXELS (too few of them, a mask below the
 *     resolution floor, a sample too wide in a*b*) cannot be revisited without
 *     the photograph;
 *   - `overexposed` has two signals and only one of them survives into the
 *     samples, so a zone caught by the clipped-pixel count alone replays clean.
 *
 * No personal data on the way out: profile ids, zone names and measurements.
 */
class ColorRuleCheckCommand extends Command
{
    protected $signature = 'color:rule-check
                            {--profile=* : Only these profile ids. Defaults to every measured profile.}
                            {--zone=* : Only these zones. Defaults to the ones that changed or failed.}
                            {--all-zones : Show every zone, including the ones that pass and agree.}
                            {--json : Print the whole replay as JSON instead of a table.}';

    protected $description = 'Replay the current thresholds over stored colour profiles';

    /**
     * Reasons that were decided on pixels nobody kept. Mirrors
     * `color_engine.rules.PIXEL_REASONS`; a profile whose zone carries one of
     * these is reported as not comparable rather than as a disagreement.
     *
     * @var list<string>
     */
    private const PIXEL_REASONS = ['eye_too_small', 'too_few_pixels', 'too_noisy'];

    public function handle(ColorAnalysisClient $client): int
    {
        $profiles = $this->profiles();
        if ($profiles->isEmpty()) {
            $this->warn('No measured profiles to check.');

            return self::SUCCESS;
        }

        $rows = [];
        $summary = [];
        $thresholds = null;

        foreach ($profiles as $profile) {
            try {
                $replay = $client->ruleCheck($profile->samples ?? []);
            } catch (ColorAnalysisUnavailable|ColorAnalysisRefused $e) {
                // Nothing partial and nothing guessed: an operator about to move
                // a threshold needs to know the answer is missing, not receive
                // one covering half the set.
                $this->error($e->getMessage());

                return self::FAILURE;
            }

            $thresholds ??= $replay['thresholds'] ?? [];
            foreach ($this->compare($profile, $replay) as $row) {
                $rows[] = $row;
                $summary[$row['verdict']] ??= 0;
                $summary[$row['verdict']]++;
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(
                ['thresholds' => $thresholds, 'rows' => $rows, 'summary' => $summary],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ));

            return self::SUCCESS;
        }

        $this->render($profiles->count(), $rows, $summary, $thresholds ?? []);

        return self::SUCCESS;
    }

    /**
     * The profiles to replay: measured ones, because an unmeasured profile has
     * no numbers to replay anything over.
     *
     * @return Collection<int, ColorProfile>
     */
    private function profiles(): Collection
    {
        $query = ColorProfile::query()
            ->where('status', ColorProfile::STATUS_OK)
            ->whereNotNull('samples')
            ->orderBy('id');

        $ids = array_filter(array_map('intval', (array) $this->option('profile')));
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    /**
     * One row per zone: what was stored, what the rules say now, and whether
     * those are the same answer.
     *
     * @param  array<string, mixed>  $replay
     * @return list<array<string, mixed>>
     */
    private function compare(ColorProfile $profile, array $replay): array
    {
        $stored = $profile->zones ?? [];
        $wanted = array_filter((array) $this->option('zone'));
        $rows = [];

        foreach ((array) ($replay['zones'] ?? []) as $zone => $now) {
            if ($wanted !== [] && ! in_array($zone, $wanted, true)) {
                continue;
            }

            $was = $stored[$zone] ?? null;
            $wasUsable = is_array($was) ? ($was['usable'] ?? null) : null;
            $wasReason = is_array($was) ? ($was['reason'] ?? null) : null;
            $decisive = $this->decisive($now);

            $rows[] = [
                'profile' => $profile->id,
                'zone' => $zone,
                'was' => $wasUsable === null ? '—' : ($wasUsable ? 'usable' : (string) ($wasReason ?? 'rejected')),
                'now' => $now['replayed'] === false
                    ? '—'
                    : ($now['usable'] ? 'usable' : (string) ($now['reason'] ?? 'rejected')),
                'decided_by' => $decisive['name'],
                'margin' => $decisive['margin'],
                'verdict' => $this->verdict($wasUsable, $wasReason, $now),
            ];
        }

        return $rows;
    }

    /**
     * The check that decided it, or — on a zone that passed — the one that came
     * closest to not letting it. A passing zone with nothing near its limit is
     * not interesting; a passing zone with 0.3 to spare is about to change its
     * mind on a threshold nudge, and that is what a review is looking for.
     *
     * @param  array<string, mixed>  $zone
     * @return array{name: string, margin: string}
     */
    private function decisive(array $zone): array
    {
        $checks = (array) ($zone['checks'] ?? []);
        if ($checks === []) {
            return ['name' => '—', 'margin' => '—'];
        }

        $name = $zone['decided_by'] ?? null;
        $check = is_string($name)
            ? collect($checks)->firstWhere('name', $name)
            : collect($checks)->sortBy('margin')->first();

        return [
            'name' => (string) ($check['name'] ?? '—'),
            'margin' => number_format((float) ($check['margin'] ?? 0), 1),
        ];
    }

    /**
     * How the stored verdict and the replayed one relate.
     *
     * `not_comparable` is not a hedge. A zone rejected on pixels was rejected on
     * evidence that no longer exists, and calling the replay's "usable" a
     * disagreement would invite somebody to loosen a threshold that was never
     * the problem.
     *
     * @param  array<string, mixed>  $now
     */
    private function verdict(?bool $wasUsable, mixed $wasReason, array $now): string
    {
        if ($wasUsable === null || $now['replayed'] === false) {
            return 'not_comparable';
        }

        if (is_string($wasReason) && in_array($wasReason, self::PIXEL_REASONS, true)) {
            return 'not_comparable';
        }

        if ($wasUsable === $now['usable']) {
            return $wasUsable ? 'still_usable' : 'still_rejected';
        }

        return $wasUsable ? 'now_rejected' : 'now_usable';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, int>  $summary
     * @param  array<string, mixed>  $thresholds
     */
    private function render(int $profiles, array $rows, array $summary, array $thresholds): void
    {
        $interesting = array_values(array_filter(
            $rows,
            fn (array $row) => $this->option('all-zones')
                || ! in_array($row['verdict'], ['still_usable', 'not_comparable'], true)
        ));

        $this->info(sprintf('%d profile(s), %d zone(s) replayed.', $profiles, count($rows)));
        $this->newLine();

        if ($interesting === []) {
            $this->line('Every comparable zone agrees with what was stored, and none was rejected.');
        } else {
            $this->table(
                ['Profile', 'Zone', 'Was', 'Now', 'Decided by', 'Margin', ''],
                array_map(fn (array $row) => [
                    $row['profile'],
                    $row['zone'],
                    $row['was'],
                    $row['now'],
                    $row['decided_by'],
                    $row['margin'],
                    $row['verdict'],
                ], $interesting)
            );
        }

        $this->newLine();
        $this->line('By outcome:');
        foreach ($summary as $verdict => $count) {
            $this->line(sprintf('  %-16s %d', $verdict, $count));
        }

        $failures = [];
        foreach ($rows as $row) {
            if (in_array($row['verdict'], ['still_rejected', 'now_rejected'], true)) {
                $failures[$row['now']] ??= 0;
                $failures[$row['now']]++;
            }
        }

        if ($failures !== []) {
            $this->newLine();
            $this->line('Rejections, by reason:');
            arsort($failures);
            foreach ($failures as $reason => $count) {
                $this->line(sprintf('  %-26s %d', $reason, $count));
            }
        }

        if ($thresholds !== []) {
            $this->newLine();
            $this->line('Thresholds used: '.json_encode($thresholds, JSON_UNESCAPED_SLASHES));
        }

        $this->newLine();
        $this->line('Nothing was changed. "not_comparable" means the rejection was decided on');
        $this->line('pixels that no longer exist, not that the rules agree.');
    }
}
