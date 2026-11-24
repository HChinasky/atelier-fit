<?php

use App\Jobs\SendAnalyticsEventJob;
use App\Models\Product;
use Illuminate\Support\Facades\Queue;

/*
 * POST /api/analytics/event — the first-party sink for browser-only events
 * (Этап A3). Allow-listed twice (event names AND per-event properties) so the
 * public endpoint cannot inject junk or PII into the analytics stream, and
 * product facts are always re-read from OUR catalog row.
 */

beforeEach(function () {
    config(['analytics.enabled' => true, 'analytics.posthog.key' => 'phc_test']);
    Queue::fake();
});

it('accepts a known UI event and enriches the product from our own catalog', function () {
    $product = Product::factory()->create([
        'brand_name' => 'Nia', 'category' => 'dresses', 'price_aed' => 590,
    ]);

    $this->postJson('/api/analytics/event', [
        'event' => 'item_selected',
        'props' => ['product_id' => $product->id],
    ])->assertNoContent();

    Queue::assertPushed(SendAnalyticsEventJob::class, function (SendAnalyticsEventJob $job) use ($product) {
        return $job->payload['event'] === 'item_selected'
            && $job->payload['properties']['product_id'] === $product->id
            && $job->payload['properties']['brand'] === 'Nia'
            && $job->payload['properties']['category'] === 'dresses'
            && $job->payload['properties']['price'] === 590.0
            && $job->payload['properties']['currency'] === 'AED';
    });
});

it('rejects an event name outside the allow-list', function () {
    $this->postJson('/api/analytics/event', [
        'event' => 'made_up_event',
        'props' => [],
    ])->assertStatus(422);

    Queue::assertNothingPushed();
});

it('drops properties that do not belong to the event', function () {
    $this->postJson('/api/analytics/event', [
        'event' => 'result_shared',
        'props' => [
            'format' => 'story',
            'method' => 'download',
            // Belongs to other events / nowhere — must not pass through.
            'time_to_view_ms' => 5,
            'reason' => 'new_photo',
        ],
    ])->assertNoContent();

    Queue::assertPushed(SendAnalyticsEventJob::class, function (SendAnalyticsEventJob $job) {
        $props = $job->payload['properties'];

        return $props['format'] === 'story'
            && $props['method'] === 'download'
            && ! array_key_exists('time_to_view_ms', $props)
            && ! array_key_exists('reason', $props);
    });
});

it('rejects free-form values even on allow-listed keys', function () {
    $this->postJson('/api/analytics/event', [
        'event' => 'result_shared',
        'props' => ['format' => 'billboard', 'method' => 'carrier_pigeon'],
    ])->assertStatus(422);

    Queue::assertNothingPushed();
});

it('rejects a product reference that does not exist in the catalog', function () {
    $this->postJson('/api/analytics/event', [
        'event' => 'shop_clicked',
        'props' => ['product_id' => 999999],
    ])->assertStatus(422);
});

it('acknowledges but queues nothing while analytics is disabled', function () {
    config(['analytics.enabled' => false]);

    $this->postJson('/api/analytics/event', [
        'event' => 'consult_clicked',
        'props' => ['looks_generated_count' => 1],
    ])->assertNoContent();

    Queue::assertNothingPushed();
});

it('is gone (404) together with the fitting-room feature flag', function () {
    config(['features.fitting_room' => false]);

    $this->postJson('/api/analytics/event', [
        'event' => 'consult_clicked',
        'props' => [],
    ])->assertNotFound();
});
