<?php

namespace App\Services\Capsules;

use App\Models\Product;
use App\Services\Color\DeltaE;
use Illuminate\Support\Collection;

/**
 * Whole looks from the catalog in a season's palette, for one occasion.
 *
 * Two shapes, never mixed: top + bottom + shoes, or dress + shoes (a dress is
 * a look on its own — "dresses" also holds suits, sets and jumpsuits). A hat
 * is added when one fits, and the look stands without it.
 *
 * What may go in: published pieces (flat_bg_verified, the operator's check)
 * that GarmentPaletteMatcher says suit the season, tagged for the occasion or
 * universal (a base piece goes with any occasion).
 *
 * What makes them a look rather than a pile:
 *   - one occasion: no piece tagged for another one, and for the occasions
 *     in capsules.require_occasion_piece (office, evening) at least one
 *     garment or the shoes tagged for it — base pieces alone would make the
 *     same looks under every tab (a hat does not count: it does not make a
 *     look an evening one);
 *   - colour balance: at most max_accents pieces in the palette's accent
 *     colours, and at least one neutral/base piece to carry them;
 *   - shoes and hat are a neutral, or in tone with a garment;
 *   - shoes made for the occasion first: universal shoes only go with
 *     garments no occasion pair fits (no ankle boots under an evening gown
 *     while there are evening heels in the member's colours);
 *   - variety: the looks of one occasion do not share garments while the
 *     catalog allows it.
 *
 * A required slot with nothing for it means no looks, and the set says which
 * slot — the page tells the member, not an empty grid.
 */
class CapsuleBuilder
{
    public function build(string $season, string $occasion): CapsuleSet
    {
        $slots = $this->candidates($season, $occasion);

        $hasSeparates = $slots['upper_body']->isNotEmpty() && $slots['lower_body']->isNotEmpty();
        $hasDresses = $slots['dresses']->isNotEmpty();

        $missing = [];
        if (! $hasSeparates && ! $hasDresses) {
            foreach (['upper_body', 'lower_body', 'dresses'] as $slot) {
                if ($slots[$slot]->isEmpty()) {
                    $missing[] = $slot;
                }
            }
        }
        if ($slots['shoes']->isEmpty()) {
            $missing[] = 'shoes';
        }

        if ($missing !== []) {
            return new CapsuleSet($occasion, [], CapsuleSet::NO_PIECES, $missing);
        }

        if ($this->requiresOccasionPiece($occasion) && ! collect(['upper_body', 'lower_body', 'dresses', 'shoes'])
            ->contains(fn (string $slot) => $slots[$slot]->contains(fn (Product $p) => $p->occasion === $occasion))) {
            return new CapsuleSet($occasion, [], CapsuleSet::NO_OCCASION_PIECES);
        }

        $looks = $this->pick($this->combinations($slots, $occasion));
        if ($looks === []) {
            return new CapsuleSet($occasion, [], CapsuleSet::NO_BALANCED_LOOK);
        }

        $looks = $this->addHeadwear($looks, $slots['headwear']);

        $capsules = array_map(fn (array $look) => $this->capsule($season, $occasion, $look['pieces']), $looks);

        // Shown best colour match first: the order agrees with the percentages
        // on the page (the occasion bonus only decides which looks are picked).
        usort($capsules, fn (Capsule $a, Capsule $b) => $b->score <=> $a->score);

        return new CapsuleSet($occasion, $capsules);
    }

    /**
     * The best pieces per slot for the season and occasion, best first: the
     * colour match, plus a small bonus for a piece made for this occasion.
     * Where a look needs such a piece, and always for shoes, those come
     * first, so the cut to candidates_per_slot never drops them for base
     * pieces.
     *
     * @return array<string, Collection<int, Product>>
     */
    private function candidates(string $season, string $occasion): array
    {
        $products = Product::query()
            ->select('products.*', 'm.score as match_score', 'm.palette_group as match_group')
            ->join('product_season_matches as m', 'm.product_id', '=', 'products.id')
            ->where('m.season', $season)
            ->where('m.suits', true)
            ->where('products.flat_bg_verified', true)
            ->fromActiveSources()
            ->whereIn('products.occasion', [$occasion, Product::OCCASION_UNIVERSAL])
            ->orderByDesc('m.score')
            ->orderBy('products.id')
            ->get()
            ->filter(fn (Product $p) => $p->measuredColours() !== []);

        $limit = (int) config('capsules.candidates_per_slot');

        return collect(Capsule::SLOTS)->mapWithKeys(fn (string $slot) => [
            $slot => $products->where('category', $slot)
                ->sortByDesc(fn (Product $p) => $this->rank($p, $occasion)
                    + (($this->requiresOccasionPiece($occasion) || $slot === 'shoes') && $p->occasion === $occasion ? 10 : 0))
                ->take($limit)
                ->values(),
        ])->all();
    }

    /**
     * Every look the rules allow, best first.
     *
     * @param  array<string, Collection<int, Product>>  $slots
     * @return list<array{pieces: array<string, Product>, rank: float}>
     */
    private function combinations(array $slots, string $occasion): array
    {
        $garmentSets = [];
        foreach ($slots['upper_body'] as $upper) {
            foreach ($slots['lower_body'] as $lower) {
                $garmentSets[] = ['upper_body' => $upper, 'lower_body' => $lower];
            }
        }
        foreach ($slots['dresses'] as $dress) {
            $garmentSets[] = ['dresses' => $dress];
        }

        $looks = [];
        foreach ($garmentSets as $garments) {
            $fitting = [];
            foreach ($slots['shoes'] as $shoes) {
                $pieces = $garments + ['shoes' => $shoes];

                if ($this->requiresOccasionPiece($occasion)
                    && ! collect($pieces)->contains(fn (Product $p) => $p->occasion === $occasion)) {
                    continue;
                }

                if ($this->allows($pieces, $shoes, $garments)) {
                    $fitting[] = $pieces;
                }
            }

            // Shoes made for the occasion win over universal ones for these
            // garments; universal shoes stand in only when none of those fit.
            $own = array_filter($fitting, fn (array $pieces) => $pieces['shoes']->occasion === $occasion);

            foreach ($own !== [] ? $own : $fitting as $pieces) {
                $looks[] = [
                    'pieces' => $pieces,
                    'rank' => array_sum(array_map(fn (Product $p) => $this->rank($p, $occasion), $pieces)) / count($pieces),
                ];
            }
        }

        usort($looks, fn (array $a, array $b) => $b['rank'] <=> $a['rank']
            ?: $this->key($a['pieces']) <=> $this->key($b['pieces']));

        return $looks;
    }

    /**
     * The rules a look must keep. $extra is the piece being added (shoes or
     * hat), held to "neutral or in tone with a garment".
     *
     * @param  array<string, Product>  $pieces
     * @param  array<string, Product>  $garments
     */
    private function allows(array $pieces, Product $extra, array $garments): bool
    {
        $occasions = collect($pieces)->pluck('occasion')
            ->reject(fn ($o) => $o === Product::OCCASION_UNIVERSAL)
            ->unique();
        if ($occasions->count() > 1) {
            return false;
        }

        $accents = count(array_filter($pieces, fn (Product $p) => $this->isAccent($p)));
        if ($accents > (int) config('capsules.max_accents') || $accents === count($pieces)) {
            return false;
        }

        return ! $this->isAccent($extra) || $this->inTone($extra, $garments);
    }

    /**
     * 3–5 looks that differ. The best look of each shape (separates, dress)
     * goes first, so a catalog with both offers both; then looks with no
     * garment or shoes repeated, then no garment repeated (shoes may: a few
     * pairs made for the occasion are worn with many outfits) — both up to
     * the maximum; then, only while there are fewer than the minimum, looks
     * that merely do not wear the same garments.
     *
     * @param  list<array{pieces: array<string, Product>, rank: float}>  $looks
     * @return list<array{pieces: array<string, Product>, rank: float}>
     */
    private function pick(array $looks): array
    {
        $min = (int) config('capsules.variants.min');
        $max = (int) config('capsules.variants.max');

        $garmentIds = fn (array $look) => array_map(
            fn (Product $p) => $p->id,
            array_diff_key($look['pieces'], ['shoes' => true, 'headwear' => true]),
        );

        $passes = [
            fn (array $look, array $chosen) => collect($chosen)->every(fn (array $c) => array_intersect(
                array_map(fn (Product $p) => $p->id, $look['pieces']),
                array_map(fn (Product $p) => $p->id, $c['pieces']),
            ) === []),
            fn (array $look, array $chosen) => collect($chosen)->every(
                fn (array $c) => array_intersect($garmentIds($look), $garmentIds($c)) === []
            ),
            fn (array $look, array $chosen) => collect($chosen)->every(
                fn (array $c) => array_values($garmentIds($look)) !== array_values($garmentIds($c))
            ),
        ];

        $chosen = collect($looks)
            ->groupBy(fn (array $look) => isset($look['pieces']['dresses']) ? Capsule::KIND_DRESS : Capsule::KIND_SEPARATES)
            ->map(fn (Collection $shape) => $shape->first())
            ->take($max)
            ->values()
            ->all();

        foreach ($passes as $index => $differs) {
            if ($index === 2 && count($chosen) >= $min) {
                break;
            }

            foreach ($looks as $look) {
                if (count($chosen) >= $max) {
                    break 2;
                }

                if ($differs($look, $chosen)) {
                    $chosen[] = $look;
                }
            }
        }

        return $chosen;
    }

    /**
     * A hat for a look that one fits — each hat once per occasion, so the
     * looks do not all wear the same one. Looks left without a hat stand
     * without it.
     *
     * @param  list<array{pieces: array<string, Product>, rank: float}>  $looks
     * @param  Collection<int, Product>  $hats
     * @return list<array{pieces: array<string, Product>, rank: float}>
     */
    private function addHeadwear(array $looks, Collection $hats): array
    {
        $worn = [];

        foreach ($looks as $index => $look) {
            $garments = array_diff_key($look['pieces'], ['shoes' => true]);
            $fitting = $hats->filter(fn (Product $hat) => $this->allows($look['pieces'] + ['headwear' => $hat], $hat, $garments));

            $hat = $fitting->first(fn (Product $h) => ! in_array($h->id, $worn, true));
            if ($hat !== null) {
                $looks[$index]['pieces']['headwear'] = $hat;
                $worn[] = $hat->id;
            }
        }

        return $looks;
    }

    /**
     * @param  array<string, Product>  $pieces
     */
    private function capsule(string $season, string $occasion, array $pieces): Capsule
    {
        $ordered = [];
        foreach (Capsule::SLOTS as $slot) {
            if (isset($pieces[$slot])) {
                $ordered[$slot] = $pieces[$slot];
            }
        }

        $accentSlots = array_keys(array_filter($ordered, fn (Product $p) => $this->isAccent($p)));
        $score = array_sum(array_map(fn (Product $p) => (float) $p->match_score, $ordered)) / count($ordered);

        return new Capsule(
            occasion: $occasion,
            kind: isset($ordered['dresses']) ? Capsule::KIND_DRESS : Capsule::KIND_SEPARATES,
            pieces: $ordered,
            score: round($score, 4),
            accents: count($accentSlots),
            explanation: $this->explain($season, $occasion, $accentSlots),
        );
    }

    /**
     * Why the look suits: the member's palette, the occasion, and how its
     * colours are balanced — all of it true of this look, nothing more.
     *
     * @param  list<string>  $accentSlots
     */
    private function explain(string $season, string $occasion, array $accentSlots): string
    {
        $replace = [
            'season' => __("seasons.names.{$season}"),
            'occasion' => __("capsules.occasion_for.{$occasion}"),
        ];

        if ($accentSlots === []) {
            return __('capsules.explain.neutral', $replace);
        }

        $replace['accents'] = implode(__('capsules.accents_glue'), array_map(
            fn (string $slot) => __("capsules.slots_lower.{$slot}"),
            $accentSlots,
        ));

        return __(count($accentSlots) === 1 ? 'capsules.explain.one_accent' : 'capsules.explain.two_accents', $replace);
    }

    private function requiresOccasionPiece(string $occasion): bool
    {
        return in_array($occasion, (array) config('capsules.require_occasion_piece'), true);
    }

    private function rank(Product $product, string $occasion): float
    {
        return (float) $product->match_score
            + ($product->occasion === $occasion ? (float) config('capsules.occasion_bonus') : 0.0);
    }

    private function isAccent(Product $product): bool
    {
        return $product->match_group === 'accents';
    }

    /**
     * @param  array<string, Product>  $garments
     */
    private function inTone(Product $piece, array $garments): bool
    {
        $lab = $this->lab($piece);

        foreach ($garments as $garment) {
            if (DeltaE::ciede2000($lab, $this->lab($garment)) <= (float) config('capsules.tone_delta_e')) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: float, 1: float, 2: float} the main colour */
    private function lab(Product $product): array
    {
        $main = $product->measuredColours()[0];

        return [(float) $main['L'], (float) $main['a'], (float) $main['b']];
    }

    /** @param array<string, Product> $pieces */
    private function key(array $pieces): string
    {
        return implode('-', array_map(fn (Product $p) => $p->id, $pieces));
    }
}
