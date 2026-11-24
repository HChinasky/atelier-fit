<?php

namespace App\Services\Feed;

use RuntimeException;

/**
 * FeedSource that reads the raw feed from a local file instead of the network.
 * Used by the dev-only `feed:seed-local` command so the local catalog fixture
 * flows through the identical import path (parse -> filter -> upsert) without a
 * partner account or any HTTP call. A missing/unreadable file throws, matching
 * the HttpFeedSource contract (the job fails loudly rather than importing nothing).
 */
class LocalFileFeedSource implements FeedSource
{
    public function __construct(private string $path) {}

    public function fetch(): string
    {
        if (! is_file($this->path) || ! is_readable($this->path)) {
            throw new RuntimeException("Local feed file not found or unreadable: {$this->path}");
        }

        $content = file_get_contents($this->path);

        if ($content === false) {
            throw new RuntimeException("Failed to read local feed file: {$this->path}");
        }

        return $content;
    }
}
