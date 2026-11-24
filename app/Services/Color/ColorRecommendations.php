<?php

namespace App\Services\Color;

use App\Models\Product;
use App\Services\Onboarding\StyleQuiz;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Catalog pieces in a season's colours, best match first.
 *
 * Only what the try-on can dress (flat_bg_verified and a try-on category, the
 * /api/catalog gate) and only what GarmentPaletteMatcher said suits the
 * season. The member's style
 * quiz narrows it: the categories they are looking for and their budget.
 *
 * A quiz can narrow it to nothing — a budget under 200 AED against a catalog
 * of 900 AED dresses. Rather than show an empty shelf, the budget is dropped
 * first and then the categories, and each result says which filters it kept,
 * so the page can word what it is showing honestly.
 */
class ColorRecommendations
{
    /**
     * @param  array<string, mixed>|null  $answers  the member's style quiz answers
     * @return array{products: Collection<int, Product>, filtered_by: list<string>}
     */
    public function forSeason(string $season, ?array $answers, int $limit = 8): array
    {
        $categories = array_values((array) ($answers['categories'] ?? []));
        $budget = StyleQuiz::BUDGETS[$answers['budget'] ?? ''] ?? null;

        $attempts = array_filter([
            ['categories' => $categories, 'budget' => $budget],
            ['categories' => $categories, 'budget' => null],
            ['categories' => [], 'budget' => null],
        ], fn (array $attempt, int $index) => $index === 0
            || ($index === 1 && $budget !== null)
            || ($index === 2 && $categories !== []), ARRAY_FILTER_USE_BOTH);

        foreach ($attempts as $attempt) {
            $products = $this->query($season, $attempt['categories'], $attempt['budget'])->limit($limit)->get();

            if ($products->isNotEmpty()) {
                return [
                    'products' => $products,
                    'filtered_by' => array_keys(array_filter([
                        'categories' => $attempt['categories'] !== [],
                        'budget' => $attempt['budget'] !== null,
                    ])),
                ];
            }
        }

        return ['products' => new Collection, 'filtered_by' => []];
    }

    /**
     * Every verified product that suits the season, for the catalog's "my
     * colours" chip. Unfiltered by the quiz: the catalog has its own filters.
     *
     * @return list<int>
     */
    public function productIds(string $season): array
    {
        return $this->query($season, [], null)->pluck('products.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @param  list<string>  $categories
     * @param  array{0: float|null, 1: float|null}|null  $budget
     * @return Builder<Product>
     */
    private function query(string $season, array $categories, ?array $budget): Builder
    {
        return Product::query()
            ->select('products.*', 'm.score as match_score', 'm.palette_group as match_group')
            ->join('product_season_matches as m', 'm.product_id', '=', 'products.id')
            ->where('m.season', $season)
            ->where('m.suits', true)
            ->where('products.flat_bg_verified', true)
            ->tryOnable()
            ->fromActiveSources()
            ->when($categories !== [], fn (Builder $q) => $q->whereIn('products.category', $categories))
            ->when($budget !== null && $budget[0] !== null, fn (Builder $q) => $q->where('products.price_aed', '>=', $budget[0]))
            ->when($budget !== null && $budget[1] !== null, fn (Builder $q) => $q->where('products.price_aed', '<', $budget[1]))
            ->orderByDesc('m.score')
            ->orderBy('products.id');
    }
}
