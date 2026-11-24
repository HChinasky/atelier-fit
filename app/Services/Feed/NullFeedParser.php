<?php

namespace App\Services\Feed;

use Illuminate\Support\Collection;

/**
 * Placeholder binding until the real feed parser lands in the next step.
 * Returns nothing, so a scheduled import runs safely (imports zero products)
 * instead of fataling on an unbound contract. The next step replaces the
 * container binding with the concrete XML/JSON parser.
 */
class NullFeedParser implements FeedParser
{
    public function parse(string $rawContent): Collection
    {
        return collect();
    }
}
