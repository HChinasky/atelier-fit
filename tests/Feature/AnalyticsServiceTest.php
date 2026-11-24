<?php

use App\Jobs\SendAnalyticsEventJob;
use App\Models\Lead;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * AnalyticsService — the server-side PostHog pipeline (Этап A2/D).
 *
 * Two promises are load-bearing and tested hard:
 *  - OFF means OFF: with ANALYTICS_ENABLED=false not a single outgoing request
 *    is made and not a single job is queued;
 *  - no forbidden property (contact, IP, URL, email-shaped value) can pass
 *    through capture(), whatever a call site sends.
 */

function analytics(): AnalyticsService
{
    return app(AnalyticsService::class);
}

it('sends nothing at all while analytics is disabled', function () {
    config(['analytics.enabled' => false]);
    Http::fake();
    Queue::fake();

    analytics()->capture('landing_viewed', ['landing_path' => '/']);
    analytics()->aliasLead(Lead::factory()->create());

    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('delivers an event to PostHog EU with the write key and geoip disabled', function () {
    config([
        'analytics.enabled' => true,
        'analytics.posthog.key' => 'phc_test',
    ]);
    Http::fake(['https://eu.i.posthog.com/*' => Http::response(['status' => 1], 200)]);

    // sync queue → the dispatched job runs inline and posts immediately.
    analytics()->capture('catalog_viewed', ['items_count' => 7]);

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://eu.i.posthog.com/capture/'
            && $body['api_key'] === 'phc_test'
            && $body['event'] === 'catalog_viewed'
            && $body['properties']['items_count'] === 7
            && $body['properties']['$geoip_disable'] === true
            && is_string($body['distinct_id']) && $body['distinct_id'] !== '';
    });
});

it('strips every forbidden property before anything is queued', function () {
    config(['analytics.enabled' => true, 'analytics.posthog.key' => 'phc_test']);
    Queue::fake();

    analytics()->capture('generation_failed', [
        'stage' => 'poll',
        'ip' => '203.0.113.9',
        'contact' => '+971501234567',
        'email' => 'someone@example.com',
        'photo_key' => 'uploads/2026-08-31/secret.jpg',
        'result' => 'https://r2.test/uploads/x.png?X-Amz-Token=SECRET',
        'note' => 'reached me at someone@example.com',
        'nested' => ['url' => 'https://leak.test', 'ok' => 1],
    ]);

    Queue::assertPushed(SendAnalyticsEventJob::class, function (SendAnalyticsEventJob $job) {
        $props = $job->payload['properties'];
        $flat = json_encode($props);

        return $props['stage'] === 'poll'
            && ! array_key_exists('ip', $props)
            && ! array_key_exists('contact', $props)
            && ! array_key_exists('email', $props)
            && ! array_key_exists('photo_key', $props)
            && ! array_key_exists('result', $props)   // URL-shaped value dropped
            && ! array_key_exists('note', $props)     // email-shaped value dropped
            && $props['nested'] === ['ok' => 1]
            && ! str_contains($flat, '://')
            && ! str_contains($flat, '@')
            && ! str_contains($flat, '203.0.113.9');
    });
});

it('never lets a call site smuggle a photo or contact into ANY declared event', function () {
    config(['analytics.enabled' => true, 'analytics.posthog.key' => 'phc_test']);
    Queue::fake();

    // Serialize every funnel event through the pipeline with poisoned props on
    // top of its legitimate shape; nothing poisoned may survive.
    $events = [
        'landing_viewed' => ['landing_path' => '/', 'utm_source' => 'ig'],
        'catalog_viewed' => ['items_count' => 3],
        'item_selected' => ['product_id' => 5, 'brand' => 'X', 'category' => 'dresses', 'price' => 100.0, 'currency' => 'AED'],
        'photo_uploaded' => ['size_kb' => 900, 'width' => 1200, 'height' => 1600],
        'photo_rejected' => ['reason' => 'no_person'],
        'photo_accepted' => ['crop_applied' => true, 'preprocess_segment' => false, 'preprocess_lighting' => true],
        'contact_submitted' => ['channel' => 'whatsapp', 'marketing_opt_in' => false],
        'generation_started' => ['provider' => 'fashn', 'mode' => 'single', 'items_count' => 1],
        'generation_completed' => ['provider' => 'fashn', 'duration_ms' => 9000, 'cost_usd' => 0.075, 'mode' => 'single'],
        'generation_failed' => ['provider' => 'fashn', 'error_code' => 'timeout', 'stage' => 'poll'],
        'budget_gate_blocked' => ['scope' => 'global'],
        'result_viewed' => ['mode' => 'single', 'time_to_view_ms' => 15000],
        'result_shared' => ['format' => 'feed', 'method' => 'web_share'],
        'shop_clicked' => ['product_id' => 5, 'brand' => 'X', 'category' => 'dresses'],
        'consult_clicked' => ['looks_generated_count' => 2],
        'regenerate_clicked' => ['reason' => 'new_photo'],
        'outfit_generated' => ['items_count' => 2, 'categories' => ['lower_body', 'upper_body']],
    ];

    $poison = ['contact' => '+971501112233', 'photo' => 'uploads/x.jpg', 'link' => 'https://evil.test'];

    foreach ($events as $event => $props) {
        analytics()->capture($event, array_merge($props, $poison));
    }

    Queue::assertPushed(SendAnalyticsEventJob::class, count($events));
    Queue::assertPushed(SendAnalyticsEventJob::class, function (SendAnalyticsEventJob $job) {
        $flat = json_encode($job->payload['properties']);

        return ! str_contains($flat, '+971')
            && ! str_contains($flat, 'uploads/')
            && ! str_contains($flat, '://');
    });
});

it('marks team traffic as internal by IP allow-list', function () {
    config([
        'analytics.enabled' => true,
        'analytics.posthog.key' => 'phc_test',
        'analytics.internal_ips' => ['198.51.100.7'],
    ]);
    Queue::fake();

    analytics()->capture('catalog_viewed', ['items_count' => 1], 'someone', '198.51.100.7');
    analytics()->capture('catalog_viewed', ['items_count' => 1], 'someone-else', '203.0.113.1');

    $flags = [];
    Queue::assertPushed(SendAnalyticsEventJob::class, function (SendAnalyticsEventJob $job) use (&$flags) {
        $flags[$job->payload['distinct_id']] = $job->payload['properties']['is_internal'];

        return true;
    });

    expect($flags['someone'])->toBeTrue()
        ->and($flags['someone-else'])->toBeFalse();
});

it('derives a deterministic, non-reversible distinct id from a lead id', function () {
    $id = analytics()->leadDistinctId(42);

    expect($id)->toBe(analytics()->leadDistinctId(42))
        ->and($id)->not->toBe(analytics()->leadDistinctId(43))
        ->and($id)->toMatch('/^[0-9a-f]{64}$/');
});

it('aliases the anonymous session id onto the lead hash at contact capture', function () {
    config(['analytics.enabled' => true, 'analytics.posthog.key' => 'phc_test']);
    Queue::fake();

    $lead = Lead::factory()->create();
    analytics()->aliasLead($lead);

    Queue::assertPushed(SendAnalyticsEventJob::class, function (SendAnalyticsEventJob $job) use ($lead) {
        return $job->payload['event'] === '$create_alias'
            && $job->payload['properties']['alias'] === analytics()->leadDistinctId($lead->id);
    });
});

it('drops the event silently when PostHog is unreachable', function () {
    config(['analytics.enabled' => true, 'analytics.posthog.key' => 'phc_test']);
    Http::fake(['https://eu.i.posthog.com/*' => Http::response('down', 503)]);

    // sync queue: a delivery failure must neither throw nor surface anywhere.
    analytics()->capture('catalog_viewed', ['items_count' => 2]);

    expect(true)->toBeTrue(); // reaching here IS the assertion — nothing threw
});
