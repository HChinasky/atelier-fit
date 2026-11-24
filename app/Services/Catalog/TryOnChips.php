<?php

namespace App\Services\Catalog;

use App\Models\Product;

/**
 * The fixed category chips of the "Примірка" step 2 (the design hand-over):
 * Сукні · Топи · Низ · Піджаки · Взуття, after "Для тебе" and "Усі". Unlike the
 * dashboard's chips they are not built from the feed: every product is filed
 * under one of these keys, the feed's own word only telling a jacket from a top.
 */
class TryOnChips
{
    /** The chip keys in the order they are drawn; labels in lang/{uk,en}/tryon_steps.php. */
    public const KEYS = ['dresses', 'tops', 'bottoms', 'jackets', 'shoes'];

    public static function of(Product $product): ?string
    {
        if ($product->category === 'shoes') {
            return 'shoes';
        }

        $jackets = array_map('mb_strtolower', (array) config('catalog.tryon_jacket_categories', []));
        if ($product->feed_category !== null && in_array(mb_strtolower($product->feed_category), $jackets, true)) {
            return 'jackets';
        }

        return match ($product->category) {
            'dresses' => 'dresses',
            'upper_body' => 'tops',
            'lower_body' => 'bottoms',
            default => null,
        };
    }
}
