<?php

namespace App\Services\Feed;

/**
 * Where ImportFeedJob gets the raw feed BYTES from, decoupled from how they are
 * parsed (FeedParser). Production reads them over HTTP (HttpFeedSource, the
 * default binding); the dev catalog seeder reads the same bytes from a local
 * file (LocalFileFeedSource) so it can drive the exact same import path with no
 * network. A failed fetch throws — the job then fails and Horizon can retry,
 * rather than silently importing nothing.
 */
interface FeedSource
{
    public function fetch(): string;
}
