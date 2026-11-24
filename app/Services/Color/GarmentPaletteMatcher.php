<?php

namespace App\Services\Color;

use App\Models\Product;
use App\Models\ProductSeasonMatch;
use App\Models\Season;
use App\Models\SeasonColor;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Does a product's colour suit a season? Judged against the season's own
 * palette, the same rows the member sees as swatches.
 *
 * For every measured colour of the garment (above min_share), the nearest
 * swatch to wear and the nearest swatch to avoid are found by CIEDE2000.
 *
 *   - the piece SUITS the season when its main colour sits within max_delta_e
 *     of a colour to wear and nearer that than any colour to avoid, and no
 *     colour covering clash_share or more of it sits nearer the avoid side;
 *   - its SCORE is how close its colours sit to the wearable ones, weighted by
 *     how much of the garment each covers: 1 is a swatch match, 0 is at the
 *     edge of max_delta_e or beyond. It orders recommendations.
 *
 * Every season gets a row, suited or not, so "why is this not in my colours"
 * has an answer in the table (delta_e) rather than an absence.
 */
class GarmentPaletteMatcher
{
    /**
     * Rewrite the product's rows against every season. A product with no
     * measured colour ends up with none.
     */
    public function match(Product $product, ?Collection $seasons = null): void
    {
        $seasons ??= Season::with('colors')->get();
        $colours = $product->measuredColours();

        $rows = $colours === [] ? [] : $seasons
            ->filter(fn (Season $season) => $season->colors->isNotEmpty())
            ->map(fn (Season $season) => ['product_id' => $product->id, 'season' => $season->slug]
                + $this->judge($colours, $season->colors)
                + ['created_at' => now(), 'updated_at' => now()])
            ->values()
            ->all();

        DB::transaction(function () use ($product, $rows) {
            ProductSeasonMatch::where('product_id', $product->id)->delete();
            if ($rows !== []) {
                ProductSeasonMatch::insert($rows);
            }
        });
    }

    /**
     * @param  list<array{L: float, a: float, b: float, share: float}>  $colours  largest share first
     * @param  Collection<int, SeasonColor>  $swatches
     * @return array{suits: bool, score: float, delta_e: float, palette_group: string|null}
     */
    public function judge(array $colours, Collection $swatches): array
    {
        $settings = (array) config('color_analysis.garment_match');
        $limit = (float) $settings['max_delta_e'];

        $wear = $swatches->filter(fn (SeasonColor $swatch) => $swatch->is_recommended)->values();
        $avoid = $swatches->reject(fn (SeasonColor $swatch) => $swatch->is_recommended)->values();

        $judged = [];
        foreach ($colours as $index => $colour) {
            if ($index > 0 && (float) $colour['share'] < (float) $settings['min_share']) {
                continue;
            }

            $lab = [(float) $colour['L'], (float) $colour['a'], (float) $colour['b']];
            [$toWear, $group] = $this->nearest($lab, $wear);
            [$toAvoid] = $this->nearest($lab, $avoid);

            $judged[] = [
                'share' => (float) $colour['share'],
                'wear' => $toWear,
                'avoid' => $toAvoid,
                'group' => $group,
                'fits' => $toWear <= $limit && $toWear < $toAvoid,
                'clashes' => $toAvoid < $toWear && $toAvoid <= $limit,
            ];
        }

        $main = $judged[0];
        $spoiled = collect($judged)->contains(
            fn (array $colour) => $colour['clashes'] && $colour['share'] >= (float) $settings['clash_share']
        );

        $covered = array_sum(array_column($judged, 'share'));
        $score = $covered > 0
            ? array_sum(array_map(
                fn (array $colour) => $colour['fits'] ? $colour['share'] * max(0.0, 1 - $colour['wear'] / $limit) : 0.0,
                $judged,
            )) / $covered
            : 0.0;

        $suits = $main['fits'] && ! $spoiled;

        return [
            'suits' => $suits,
            'score' => round($suits ? $score : 0.0, 4),
            'delta_e' => round($main['wear'], 3),
            'palette_group' => $main['fits'] ? $main['group'] : null,
        ];
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $lab
     * @param  Collection<int, SeasonColor>  $swatches
     * @return array{0: float, 1: string|null} distance, and the swatch's group
     */
    private function nearest(array $lab, Collection $swatches): array
    {
        $best = [INF, null];

        foreach ($swatches as $swatch) {
            $distance = DeltaE::ciede2000($lab, [$swatch->lab_l, $swatch->lab_a, $swatch->lab_b]);
            if ($distance < $best[0]) {
                $best = [$distance, $swatch->group];
            }
        }

        return $best;
    }
}
