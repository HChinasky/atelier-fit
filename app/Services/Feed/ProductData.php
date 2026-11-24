<?php

namespace App\Services\Feed;

/**
 * Normalized product coming out of a FeedParser, decoupled from the raw feed
 * format. Fields map 1:1 onto the products table; `raw` carries the untouched
 * source object to be stored as jsonb.
 */
final readonly class ProductData
{
    public function __construct(
        public string $externalId,
        public string $brandName,
        public string $productName,
        // Money as a decimal string (e.g. "149.00"), aligned with the
        // decimal(10,2) column. Never a float — that would lose precision
        // before the value ever reaches the database.
        public string $priceAed,
        public string $imageFlatUrl,
        public string $shopUrl,
        public string $category,
        public array $raw = [],
        // The category the FEED files the product under ("Платья", "Dresses"),
        // as opposed to $category, the try-on region. Null when the feed says
        // nothing; the account's category chips are built from it.
        public ?string $feedCategory = null,
    ) {}
}
