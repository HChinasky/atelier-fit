<?php

namespace App\Services\Catalog;

use App\Models\Product;
use Illuminate\Support\Facades\Lang;

/**
 * The category chips of the account pages, built from what the catalog
 * actually holds: each product carries the category its feed filed it under
 * (products.feed_category), and a chip is one such category, worded for the
 * interface: config('catalog.category_labels') sends the feed word to a chip
 * key, lang/<locale>/catalog.php words the key in the visitor's language.
 *
 * The label map exists for two reasons: the feeds speak different languages
 * (the Amazon.ae catalog says "Платья", the curated feed says "Dresses") and
 * the interface has its own language, and two feeds' words for the same thing must land
 * on ONE chip. A feed category the map has no word for is shown as it came,
 * never dropped: a filter that hides products is worse than a foreign word.
 */
final class CategoryChips
{
    /**
     * The chip a product sits under, or null when its feed said nothing.
     */
    public static function label(?string $feedCategory): ?string
    {
        $feedCategory = trim((string) $feedCategory);
        if ($feedCategory === '') {
            return null;
        }

        foreach ((array) config('catalog.category_labels', []) as $source => $chip) {
            if (mb_strtolower((string) $source) === mb_strtolower($feedCategory)) {
                return self::words((string) $chip);
            }
        }

        return $feedCategory;
    }

    /**
     * A chip key in the request's language. A value with no translation is
     * already the words (a map written as labels, or a language that has not
     * named this chip yet) and is shown as it is.
     */
    private static function words(string $chip): string
    {
        $key = 'catalog.categories.'.$chip;

        return Lang::has($key) ? (string) __($key) : $chip;
    }

    /**
     * Ordered, de-duplicated chips for a set of products: the configured
     * labels first in their configured order, then any unmapped feed word
     * alphabetically. Only categories that are actually present appear, so
     * no chip ever filters down to nothing.
     *
     * @param  iterable<Product>  $products
     * @return list<string>
     */
    public static function for(iterable $products): array
    {
        $present = [];
        foreach ($products as $product) {
            if ($label = self::label($product->feed_category)) {
                $present[$label] = true;
            }
        }

        $known = array_values(array_unique(array_map(
            fn ($chip) => self::words((string) $chip),
            (array) config('catalog.category_labels', []),
        )));

        $ordered = array_values(array_filter($known, fn ($label) => isset($present[$label])));

        $rest = array_diff(array_keys($present), $known);
        sort($rest, SORT_STRING | SORT_FLAG_CASE);

        return array_merge($ordered, array_values($rest));
    }
}
