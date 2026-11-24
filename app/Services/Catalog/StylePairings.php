<?php

namespace App\Services\Catalog;

use App\Models\Product;
use Illuminate\Support\Collection;

/**
 * "Стилізуй з цим" on the product detail: a few pieces from OTHER category
 * chips that go with the one on screen. The rule is deliberately plain — no
 * stylist's judgement is pretended:
 *
 *   - never the piece itself, never its own chip (no jeans for jeans);
 *   - complements first: tops for bottoms, bottoms for tops, a top or a
 *     jacket over a dress / a full look;
 *   - then any other chip, so a small catalog still fills the row;
 *   - each group is rotated by the piece's id, so neighbouring pieces do not
 *     all show the same four.
 */
final class StylePairings
{
    /** FASHN category → the categories that complete it, best first. */
    private const COMPLEMENTS = [
        'upper_body' => ['lower_body'],
        'lower_body' => ['upper_body'],
        'dresses' => ['upper_body'],
    ];

    /**
     * @param  Collection<int, Product>  $products
     * @return array<int, list<int>> product id → ids of the pieces to pair it with
     */
    public static function for(Collection $products, int $limit = 4): array
    {
        $pairings = [];

        foreach ($products as $product) {
            $others = $products->filter(fn (Product $p) => $p->id !== $product->id
                && self::chip($p) !== self::chip($product))->values();

            $wanted = self::COMPLEMENTS[$product->category] ?? [];
            [$preferred, $rest] = $others->partition(fn (Product $p) => in_array($p->category, $wanted, true));

            $pairings[$product->id] = self::rotate($preferred->values(), $product->id)
                ->concat(self::rotate($rest->values(), $product->id))
                ->take($limit)
                ->pluck('id')
                ->all();
        }

        return $pairings;
    }

    /** The chip a piece is filed under; without one, its FASHN category stands in. */
    private static function chip(Product $product): string
    {
        return (string) ($product->category_label ?? $product->category);
    }

    /**
     * @param  Collection<int, Product>  $items
     * @return Collection<int, Product>
     */
    private static function rotate(Collection $items, int $seed): Collection
    {
        if ($items->isEmpty()) {
            return $items;
        }

        $offset = $seed % $items->count();

        return $items->slice($offset)->concat($items->take($offset))->values();
    }
}
