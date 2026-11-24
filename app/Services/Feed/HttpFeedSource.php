<?php

namespace App\Services\Feed;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Production FeedSource: fetches the raw partner feed over HTTP from
 * config('feed.url'), with the configured timeout and an optional bearer token
 * for protected feeds. A failed source is logged and rethrown so the job fails
 * (and Horizon can retry) instead of silently importing nothing.
 *
 * This is the default FeedSource binding; it preserves the exact fetch behaviour
 * the import job had before the source was extracted behind this contract.
 */
class HttpFeedSource implements FeedSource
{
    public function fetch(): string
    {
        $url = (string) config('feed.url');

        try {
            $request = Http::timeout((int) config('feed.timeout', 30));

            if ($token = config('feed.auth_token')) {
                $request = $request->withToken($token);
            }

            return $request->get($url)->throw()->body();
        } catch (Throwable $e) {
            Log::error('[import-feed] failed to fetch feed source', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
