<?php

namespace App\Services\Outfit;

use App\Models\Product;

/**
 * Validates a chosen SET of garments and resolves the wearing order, BEFORE any
 * fal call — an invalid set is rejected with a localized reason and spends
 * nothing. Pure decision logic (no engine, no HTTP), mirroring the photo gate.
 *
 * Rules (all data-driven from config('fitting_room.outfit'), so adding a layer
 * type or raising the cap needs no code change here):
 *   - size must be between 2 and max_items;
 *   - 'dresses' is solo-only — never part of a set;
 *   - every garment's category must be a known layer (in layer_order);
 *   - no two garments may occupy the SAME layer (no two tops, no two bottoms).
 *
 * On success the set is re-ordered into wearing order (base layer first) so the
 * chain dresses bottom-up regardless of the order the user picked.
 */
class OutfitValidator
{
    /**
     * @param  iterable<Product>  $products
     */
    public function validate(iterable $products): OutfitPlan
    {
        $items = is_array($products) ? array_values($products) : iterator_to_array($products, false);

        $order = array_values((array) config('fitting_room.outfit.layer_order', []));
        $fullBodyLayers = (array) config('fitting_room.outfit.full_body_layers', []);
        $max = (int) config('fitting_room.outfit.max_items', 2);

        if (count($items) < 2) {
            return OutfitPlan::rejected(OutfitRejection::TooFewItems);
        }

        if (count($items) > $max) {
            return OutfitPlan::rejected(OutfitRejection::TooManyItems);
        }

        $seenLayers = [];
        foreach ($items as $product) {
            $category = $product->category;

            // A dress is a complete look — it can never share a set.
            if ($category === 'dresses') {
                return OutfitPlan::rejected(OutfitRejection::DressMustBeSolo);
            }

            // Only known layer types may join a set.
            if (! in_array($category, $order, true)) {
                return OutfitPlan::rejected(OutfitRejection::UnsupportedCategory);
            }

            // One garment per layer — no two tops, no two bottoms.
            if (in_array($category, $seenLayers, true)) {
                return OutfitPlan::rejected(OutfitRejection::DuplicateLayer);
            }

            $seenLayers[] = $category;
        }

        // Re-order into wearing order (base layer first) by the configured layer
        // index — independent of the user's pick order.
        usort(
            $items,
            static fn (Product $a, Product $b): int => array_search($a->category, $order, true) <=> array_search($b->category, $order, true)
        );

        $fullBody = (bool) array_intersect($seenLayers, $fullBodyLayers);

        return OutfitPlan::valid($items, $fullBody);
    }
}
