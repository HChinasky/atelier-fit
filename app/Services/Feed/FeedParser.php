<?php

namespace App\Services\Feed;

use Illuminate\Support\Collection;

/**
 * Contract between the import orchestration (ImportFeedJob) and the concrete
 * feed-format parsing, which is implemented in a later step. Given the raw feed
 * payload, returns normalized ProductData DTOs. The job depends ONLY on this
 * interface; swapping in the real XML/JSON parser is a binding change.
 *
 * @phpstan-return Collection<int, ProductData>
 */
interface FeedParser
{
    /**
     * @return Collection<int, ProductData>
     */
    public function parse(string $rawContent): Collection;
}
