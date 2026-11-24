<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\Catalog\StyleTagger;
use App\Services\Feed\FeedParser;
use App\Services\Feed\FeedSource;
use App\Services\Feed\FlatBackgroundVerifier;
use App\Services\Feed\ProductData;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrates the daily catalog import: fetch the raw feed → hand it to the
 * FeedParser contract → filter by configured brands → upsert into products.
 *
 * This job knows NOTHING about where the bytes come from or the feed format:
 * fetching lives behind FeedSource, parsing behind FeedParser, and photo
 * verification behind FlatBackgroundVerifier — all injected. Production fetches
 * over HTTP (HttpFeedSource); the dev seeder swaps in a local-file source to run
 * the exact same path offline. Runs on the Redis queue via Horizon
 * (ShouldQueue), never synchronously.
 */
class ImportFeedJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The allow-list entry that lets every brand through. The list exists to
     * keep a partner feed of thousands of items down to the brands the stylist
     * curates; a feed that was assembled by hand IS that curation, and listing
     * its two dozen marketplace brands in FEED_BRANDS would only be noise.
     */
    public const ANY_BRAND = '*';

    public function handle(FeedSource $source, FeedParser $parser, FlatBackgroundVerifier $verifier, StyleTagger $styles): void
    {
        $content = $source->fetch();

        $allowedBrands = $this->normalizedAllowedBrands();
        $anyBrand = $allowedBrands->contains(self::ANY_BRAND);

        $imported = 0;
        $skippedInvalid = 0;
        $filteredBrand = 0;

        foreach ($parser->parse($content) as $product) {
            if (! $anyBrand && ! $allowedBrands->contains($this->normalize($product->brandName))) {
                $filteredBrand++;

                continue;
            }

            if (! $this->isValid($product)) {
                $skippedInvalid++;
                Log::warning('[import-feed] skipped invalid product', [
                    'external_id' => $product->externalId,
                    'brand' => $product->brandName,
                    'category' => $product->category,
                ]);

                continue;
            }

            try {
                // Style/occasion follow the text the feed just wrote; a tag
                // a stylist or the AI set is left alone (StyleTagger).
                $styles->tag($this->upsert($product, $verifier));
                $imported++;
            } catch (Throwable $e) {
                // One bad row must never sink the whole import.
                $skippedInvalid++;
                Log::warning('[import-feed] failed to upsert product, skipping', [
                    'external_id' => $product->externalId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('[import-feed] import finished', [
            'imported' => $imported,
            'skipped_invalid' => $skippedInvalid,
            'filtered_other_brand' => $filteredBrand,
        ]);
    }

    /**
     * Idempotent upsert keyed on external_id (ON CONFLICT (external_id) DO
     * UPDATE semantics): re-running the same feed updates mutable fields
     * (price, etc.) in place instead of creating duplicates. The full raw
     * object is persisted to jsonb.
     */
    private function upsert(ProductData $product, FlatBackgroundVerifier $verifier): Product
    {
        return Product::updateOrCreate(
            ['external_id' => $product->externalId],
            [
                'brand_name' => $product->brandName,
                'product_name' => $product->productName,
                'price_aed' => $product->priceAed,
                'image_flat_url' => $product->imageFlatUrl,
                'shop_url' => $product->shopUrl,
                'category' => $product->category,
                'feed_category' => $product->feedCategory,
                'flat_bg_verified' => $verifier->verify($product),
                'raw' => $product->raw,
            ]
        );
    }

    /**
     * Guard against incomplete/malformed items before they reach the DB (a
     * constraint violation inside a transaction would poison it).
     */
    private function isValid(ProductData $product): bool
    {
        return $product->externalId !== ''
            && $product->brandName !== ''
            && $product->productName !== ''
            && $product->imageFlatUrl !== ''
            && $product->shopUrl !== ''
            // numeric and non-negative, checked WITHOUT casting to float
            && is_numeric($product->priceAed)
            && ! str_starts_with(ltrim($product->priceAed), '-')
            && in_array($product->category, Product::CATEGORIES, true);
    }

    /**
     * @return Collection<int, string>
     */
    private function normalizedAllowedBrands(): Collection
    {
        return collect((array) config('feed.brands', []))->map(fn ($b) => $this->normalize($b));
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
