<?php

namespace Tests\Support;

use App\Services\Feed\FeedParser;
use App\Services\Feed\ProductData;
use Illuminate\Support\Collection;

/**
 * Test double for FeedParser: ignores the raw content and returns a preset list
 * of ProductData. Lets ImportFeedJob be exercised without any real feed format
 * or network — the orchestration is what's under test here.
 */
class FakeFeedParser implements FeedParser
{
    /** @param array<int, ProductData> $products */
    public function __construct(private array $products) {}

    public function parse(string $rawContent): Collection
    {
        return collect($this->products);
    }
}
