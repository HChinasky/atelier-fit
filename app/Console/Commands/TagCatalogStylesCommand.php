<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\Catalog\StyleTagger;
use Illuminate\Console\Command;

/**
 * (Re-)read every product's style and occasion from its words (StyleTagger).
 *
 * Idempotent: a product whose text has not changed gets the same tag and is
 * not written again. A tag set by a stronger source (feed, ai, manual) is
 * never touched — those products are counted as kept.
 *
 *   php artisan catalog:tag-styles
 *
 * The import tags new and changed products itself; this is for a catalog
 * that predates the tagger, and for after the keyword list changes.
 */
class TagCatalogStylesCommand extends Command
{
    protected $signature = 'catalog:tag-styles';

    protected $description = 'Parse style and occasion tags for catalog products from their titles and descriptions';

    public function handle(StyleTagger $tagger): int
    {
        $written = 0;
        $kept = 0;

        Product::query()->lazyById()->each(function (Product $product) use ($tagger, &$written, &$kept) {
            if (! $tagger->mayOverwrite($product->style_source)) {
                $kept++;

                return;
            }

            if ($tagger->tag($product)) {
                $written++;
            }
        });

        $this->info("{$written} product(s) tagged, {$kept} kept (set by a stronger source).");

        $this->table(
            ['occasion', 'products', 'from parsing'],
            collect(Product::OCCASIONS)->map(fn (string $occasion) => [
                $occasion,
                Product::where('occasion', $occasion)->count(),
                Product::where('occasion', $occasion)->where('style_source', StyleTagger::SOURCE)->count(),
            ])->all(),
        );

        return self::SUCCESS;
    }
}
