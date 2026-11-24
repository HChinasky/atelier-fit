<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;

/**
 * Delivers ONE analytics event to PostHog EU. Fire-and-forget by contract:
 * three quick attempts, and if PostHog is down or refuses the event, the event
 * is dropped silently — failed() swallows the exception, nothing lands in the
 * failed-jobs table and nothing is ever surfaced to a visitor. Analytics is a
 * lens on the product, never a dependency of it.
 *
 * The payload arrives fully sanitized from AnalyticsService (no PII, no URLs,
 * no IP); this job adds only the write key and posts it.
 */
class SendAnalyticsEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Seconds between retries — short: stale analytics is worthless. */
    public int $backoff = 10;

    /**
     * @param  array{event: string, distinct_id: string, properties: array<string, mixed>, timestamp: string}  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(): void
    {
        if (! (bool) config('analytics.enabled')) {
            return; // flag flipped off while the event sat in the queue
        }

        $key = (string) config('analytics.posthog.key');

        if ($key === '') {
            return; // not configured — drop, never error
        }

        try {
            Http::timeout((int) config('analytics.posthog.timeout', 5))
                ->post((string) config('analytics.posthog.host').'/capture/', [
                    'api_key' => $key,
                    ...$this->payload,
                ])
                ->throw();
        } catch (\Throwable $e) {
            // Retry transport hiccups a couple of times, then DROP: the job is
            // released by hand (never re-thrown) so an unreachable PostHog can
            // not fill failed_jobs or page anyone.
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff);
            }
        }
    }
}
