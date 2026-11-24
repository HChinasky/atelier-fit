<?php

namespace App\Services\Color;

use App\Models\ColorProfile;
use App\Models\ColorProfileLabel;
use Illuminate\Support\Collection;

/**
 * What the engine says, against what a stylist says.
 *
 * This is the instrument the anchor points eventually get set from, and it is
 * deliberately only an instrument: it computes, it does not conclude. Nothing
 * here proposes a threshold, moves one, or writes anything back into the
 * classifier data. A person reads it and decides.
 *
 * **The axis buckets below are this report's reading, not the engine's.** The
 * engine's axes are continuous and a stylist's vocabulary is not, so comparing
 * them at all requires a cut somewhere, and there is no principled place to put
 * it yet — that is the thing being calibrated. Thirds of the range, because a
 * three-way split with equal parts is the one choice that does not quietly
 * favour an answer. They are used for the agreement columns and nowhere else.
 */
class LabelReport
{
    /**
     * Where the engine's continuous temperature is called cool, neutral, warm.
     * A third of the range each. See the class docstring.
     */
    public const AXIS_CUT = 1 / 3;

    /** The five measurements the anchor points are actually set from. */
    public const FEATURES = [
        'skin_h' => 'Skin hue h°',
        'skin_c' => 'Skin chroma C*',
        'skin_l' => 'Skin lightness L*',
        'hair_l' => 'Hair lightness L*',
        'contrast' => 'Contrast (0..1)',
    ];

    /**
     * Build the whole thing.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $rows = $this->rows();

        return [
            'generated_at' => now()->toIso8601String(),
            'note' => 'Computed, not concluded. This report changes no thresholds and proposes '
                .'none. The axis buckets in it are the report\'s own reading of the engine\'s '
                .'continuous axes, used for the agreement columns and nowhere else.',
            'all' => $this->analyse($rows),
            // The same again over the frames where an illuminant estimate was
            // actually applied. Half the point of collecting a calibration set
            // is to find out whether the correction helps, and an aggregate
            // over both kinds cannot answer that.
            'corrected' => $this->analyse(
                $rows->filter(fn (array $r) => $r['wb_method'] !== null && $r['wb_method'] !== 'none')
            ),
        ];
    }

    /**
     * One flat row per label, with everything the report reads pulled out.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function rows(): Collection
    {
        return ColorProfileLabel::query()
            ->with('profile')
            ->orderBy('color_profile_id')
            ->get()
            ->filter(fn (ColorProfileLabel $l) => $l->profile !== null)
            ->map(function (ColorProfileLabel $label): array {
                /** @var ColorProfile $profile */
                $profile = $label->profile;
                $samples = $profile->samples ?? [];
                $axes = $profile->axes ?? [];

                return [
                    'profile_id' => $profile->id,
                    'person_ref' => $profile->person_ref,
                    'engine_season' => $profile->season,
                    'engine_top2' => array_keys($profile->rankedSeasons()),
                    'engine_confidence' => $profile->confidence,
                    'wb_method' => $profile->white_balance['method'] ?? null,
                    'stylist_season' => $label->season,
                    'stylist_alt' => $label->season_alt,
                    'stylist_confidence' => $label->stylist_confidence,
                    'undertone' => $label->undertone,
                    'depth' => $label->depth,
                    'chroma' => $label->chroma,
                    // The engine's own axes, bucketed into the stylist's words.
                    'engine_undertone' => $this->bucket($axes['temperature'] ?? null, ['cool', 'neutral', 'warm']),
                    'engine_depth' => $this->bucket($axes['depth'] ?? null, ['light', 'medium', 'deep']),
                    'engine_chroma' => $this->split($axes['chroma'] ?? null, 'soft', 'bright'),
                    'features' => [
                        'skin_h' => $samples['skin']['h'] ?? null,
                        'skin_c' => $samples['skin']['C'] ?? null,
                        'skin_l' => $samples['skin']['L'] ?? null,
                        'hair_l' => $samples['hair']['L'] ?? null,
                        'contrast' => $profile->contrast['value'] ?? null,
                    ],
                ];
            })
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function analyse(Collection $rows): array
    {
        return [
            'labelled' => $rows->count(),
            'profiles' => $rows->pluck('profile_id')->unique()->count(),
            'by_stylist_confidence' => $this->tally($rows, 'stylist_confidence'),
            'agreement' => $this->agreement($rows),
            'confusion' => $this->confusion($rows),
            'distributions' => $this->distributions($rows),
            'worst' => $this->worst($rows),
        ];
    }

    /**
     * How often the two agree, four ways.
     *
     * Each is a stricter or looser reading of the same question, and they are
     * reported side by side because which one matters depends on what the
     * classifier is for. Exact agreement is the headline; family agreement is
     * the floor below which nothing is working; top-2 sits between them and is
     * the honest one for a system that returns two seasons.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function agreement(Collection $rows): array
    {
        $comparable = $rows->filter(fn (array $r) => $r['engine_season'] !== null);

        $exact = $comparable->filter(
            fn (array $r) => $r['engine_season'] === $r['stylist_season']
        );

        // The stylist's season appears in the engine's top two. The engine
        // returns two on purpose, so judging it on the first alone reports on a
        // product nobody built.
        $topTwo = $comparable->filter(
            fn (array $r) => in_array($r['stylist_season'], $r['engine_top2'], true)
        );

        // Same family — spring, summer, autumn, winter. The slugs end in their
        // family, so this is a suffix and not a lookup table. It is the floor:
        // calling an autumn a winter is a different kind of wrong from calling
        // a true autumn a deep autumn, and an aggregate that cannot tell them
        // apart hides which one is happening.
        $family = $comparable->filter(
            fn (array $r) => ColorProfileLabel::family($r['engine_season']) !== null
                && ColorProfileLabel::family($r['engine_season'])
                    === ColorProfileLabel::family($r['stylist_season'])
        );

        return [
            'comparable' => $comparable->count(),
            'exact' => $this->rate($exact->count(), $comparable->count()),
            'within_top_2' => $this->rate($topTwo->count(), $comparable->count()),
            'same_family' => $this->rate($family->count(), $comparable->count()),
            // Per axis, over the rows where the stylist judged that axis at all.
            // Left blank means "not judged", which is not a disagreement.
            'axes' => [
                'undertone' => $this->axisAgreement($rows, 'undertone'),
                'depth' => $this->axisAgreement($rows, 'depth'),
                'chroma' => $this->axisAgreement($rows, 'chroma'),
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function axisAgreement(Collection $rows, string $axis): array
    {
        $judged = $rows->filter(
            fn (array $r) => $r[$axis] !== null && $r['engine_'.$axis] !== null
        );
        $same = $judged->filter(fn (array $r) => $r[$axis] === $r['engine_'.$axis]);

        return $this->rate($same->count(), $judged->count()) + ['judged' => $judged->count()];
    }

    /**
     * What the engine calls it, when the stylist says X.
     *
     * Rows are the stylist's verdict and columns the engine's, because the
     * question being asked is "where does the engine go wrong", and the
     * stylist's answer is the one standing in for the truth.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, array<string, int>>
     */
    private function confusion(Collection $rows): array
    {
        $matrix = [];
        foreach (ColorProfileLabel::SEASONS as $stylist) {
            $matrix[$stylist] = array_fill_keys(ColorProfileLabel::SEASONS, 0);
        }

        foreach ($rows as $row) {
            $engine = $row['engine_season'];
            if ($engine === null || ! isset($matrix[$row['stylist_season']][$engine])) {
                continue;
            }
            $matrix[$row['stylist_season']][$engine]++;
        }

        return $matrix;
    }

    /**
     * Where each measurement actually lands, grouped by what a stylist called
     * it. This is the raw material for the anchor points, and the reason the
     * report exists.
     *
     * Median and the 10th/90th percentile rather than mean and standard
     * deviation: these are small, skewed samples with outliers that are real
     * faces rather than noise, and a mean would be dragged about by them.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function distributions(Collection $rows): array
    {
        $out = [];
        foreach (['stylist_season' => 'season', 'undertone' => 'undertone', 'depth' => 'depth', 'chroma' => 'chroma'] as $field => $name) {
            $groups = [];
            foreach ($rows->groupBy($field) as $value => $group) {
                if ($value === '' || $value === null) {
                    continue;
                }
                $groups[(string) $value] = [
                    'n' => $group->count(),
                    'features' => collect(array_keys(self::FEATURES))
                        ->mapWithKeys(fn (string $key) => [
                            $key => $this->spread($group->pluck('features.'.$key)),
                        ])
                        ->all(),
                ];
            }
            ksort($groups);
            $out[$name] = $groups;
        }

        return $out;
    }

    /**
     * The disagreements worth a person's time, hardest first.
     *
     * Ordered by how wrong and how sure: a family mismatch beats a
     * within-family one, and among equals a confident engine beats an unsure
     * one. A wrong answer the engine was sure about is where a threshold is
     * actually misplaced; a wrong answer it already flagged as low confidence
     * is the system working.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function worst(Collection $rows): array
    {
        return $rows
            ->filter(fn (array $r) => $r['engine_season'] !== null
                && $r['engine_season'] !== $r['stylist_season'])
            ->map(function (array $row): array {
                $familyMiss = ColorProfileLabel::family($row['engine_season'])
                    !== ColorProfileLabel::family($row['stylist_season']);

                return $row + [
                    'family_mismatch' => $familyMiss,
                    'severity' => ($familyMiss ? 1000 : 0) + (float) ($row['engine_confidence'] ?? 0) * 100,
                ];
            })
            ->sortByDesc('severity')
            ->take(25)
            ->values()
            ->all();
    }

    // --- small arithmetic ---------------------------------------------------

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function tally(Collection $rows, string $field): array
    {
        $counts = array_fill_keys(ColorProfileLabel::CONFIDENCES, 0);
        foreach ($rows as $row) {
            if (isset($counts[$row[$field]])) {
                $counts[$row[$field]]++;
            }
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    private function rate(int $hits, int $of): array
    {
        return [
            'hits' => $hits,
            'of' => $of,
            'rate' => $of === 0 ? null : round($hits / $of, 4),
        ];
    }

    /**
     * Count, median and the 10th/90th percentile of one measurement.
     *
     * @param  Collection<int, mixed>  $values
     * @return array<string, mixed>
     */
    private function spread(Collection $values): array
    {
        $numbers = $values->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (float) $v)
            ->sort()
            ->values()
            ->all();

        if ($numbers === []) {
            return ['n' => 0, 'p10' => null, 'median' => null, 'p90' => null];
        }

        return [
            'n' => count($numbers),
            'p10' => $this->percentile($numbers, 0.10),
            'median' => $this->percentile($numbers, 0.50),
            'p90' => $this->percentile($numbers, 0.90),
        ];
    }

    /**
     * @param  list<float>  $sorted
     */
    private function percentile(array $sorted, float $at): float
    {
        $index = (int) round($at * (count($sorted) - 1));

        return round($sorted[$index], 3);
    }

    /**
     * A continuous axis in the stylist's three words. See the class docstring.
     *
     * @param  list<string>  $words  low, middle, high
     */
    private function bucket(mixed $value, array $words): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }
        $value = (float) $value;

        if ($value <= -self::AXIS_CUT) {
            return $words[0];
        }

        return $value >= self::AXIS_CUT ? $words[2] : $words[1];
    }

    /**
     * The same, for an axis a stylist only has two words for.
     */
    private function split(mixed $value, string $low, string $high): ?string
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (float) $value < 0 ? $low : $high;
    }
}
