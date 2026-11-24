<?php

use App\Jobs\GenerateLookJob;
use App\Jobs\SendAnalyticsEventJob;
use App\Models\Generation;
use App\Models\Lead;
use App\Models\PhotoConsent;
use App\Models\Product;
use App\Services\Analytics\AnalyticsService;
use App\Services\Fashn\TryOnProvider;
use App\Services\Share\ShareCardComposer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * Этап A3 — the server-side funnel events fire from the EXISTING controllers
 * and jobs, on the lead's pseudonymous distinct id, with only the declared
 * properties. Every test runs with analytics enabled and the queue faked, so
 * what is asserted is exactly what would leave for PostHog.
 */

beforeEach(function () {
    config(['analytics.enabled' => true, 'analytics.posthog.key' => 'phc_test']);
    Storage::fake('r2');
});

/** Run GenerateLookJob's handle() directly, bypassing the (faked) queue. */
function runLookJob(Generation $generation): void
{
    (new GenerateLookJob($generation))->handle(
        app(TryOnProvider::class),
        app(ShareCardComposer::class),
        app(AnalyticsService::class),
    );
}

/** The queued analytics payloads for one event name. */
function queuedEvents(string $event): array
{
    $payloads = [];

    Queue::assertPushed(SendAnalyticsEventJob::class, function (SendAnalyticsEventJob $job) use ($event, &$payloads) {
        if ($job->payload['event'] === $event) {
            $payloads[] = $job->payload;
        }

        return true;
    });

    return $payloads;
}

it('captures catalog_viewed with the item count', function () {
    Queue::fake();
    Product::factory()->count(3)->create(['flat_bg_verified' => true]);

    $this->getJson('/api/catalog')->assertOk();

    $events = queuedEvents('catalog_viewed');
    expect($events)->toHaveCount(1)
        ->and($events[0]['properties']['items_count'])->toBe(3);
});

it('captures photo_uploaded with size and dimensions — never the photo itself', function () {
    Queue::fake();

    $this->postJson('/api/fitting-room/photo', [
        'photo' => UploadedFile::fake()->image('me.jpg', 640, 960),
        'consent_accepted' => '1',
        'consent_version' => (string) config('consent.version'),
        'terms_accepted' => '1',
        'terms_version' => (string) config('terms.version'),
    ])->assertCreated();

    $events = queuedEvents('photo_uploaded');
    expect($events)->toHaveCount(1)
        ->and($events[0]['properties']['width'])->toBe(640)
        ->and($events[0]['properties']['height'])->toBe(960)
        ->and(json_encode($events[0]))->not->toContain('uploads/');
});

it('captures contact_submitted on the lead hash and aliases the anonymous id', function () {
    Queue::fake();

    $response = $this->postJson('/api/lead', [
        'channel' => 'whatsapp',
        'contact' => '+971501234567',
        'marketing_opt_in' => true,
    ])->assertCreated();

    $hash = app(AnalyticsService::class)->leadDistinctId($response->json('lead_id'));

    $submitted = queuedEvents('contact_submitted');
    expect($submitted)->toHaveCount(1)
        ->and($submitted[0]['distinct_id'])->toBe($hash)
        ->and($submitted[0]['properties']['channel'])->toBe('whatsapp')
        ->and($submitted[0]['properties']['marketing_opt_in'])->toBeTrue()
        // The contact value itself must never ride along.
        ->and(json_encode($submitted[0]))->not->toContain('+971501234567');

    expect(queuedEvents('$create_alias'))->toHaveCount(1);
});

it('captures photo_accepted and generation_started when a try-on is queued', function () {
    Queue::fake();

    $lead = Lead::factory()->create();
    $product = Product::factory()->create(['flat_bg_verified' => true]);
    Storage::disk('r2')->put('uploads/2026-08-31/in.jpg', 'BYTES');

    $this->postJson('/api/fitting-room/generate', [
        'lead_id' => $lead->id,
        'product_id' => $product->id,
        'photo_key' => 'uploads/2026-08-31/in.jpg',
        'consent_id' => PhotoConsent::factory()->create()->id,
    ])->assertAccepted();

    $hash = app(AnalyticsService::class)->leadDistinctId($lead->id);

    $accepted = queuedEvents('photo_accepted');
    $started = queuedEvents('generation_started');

    expect($accepted)->toHaveCount(1)
        ->and($accepted[0]['distinct_id'])->toBe($hash)
        ->and($started)->toHaveCount(1)
        ->and($started[0]['properties'])->toMatchArray([
            'provider' => 'fashn', 'mode' => 'single', 'items_count' => 1,
        ]);
});

it('captures budget_gate_blocked with the global scope when the cap is hit', function () {
    Queue::fake();
    config(['fitting_room.generation.global_cap' => 0]);

    $lead = Lead::factory()->create();
    $product = Product::factory()->create(['flat_bg_verified' => true]);
    Storage::disk('r2')->put('uploads/2026-08-31/in.jpg', 'BYTES');

    $this->postJson('/api/fitting-room/generate', [
        'lead_id' => $lead->id,
        'product_id' => $product->id,
        'photo_key' => 'uploads/2026-08-31/in.jpg',
        'consent_id' => PhotoConsent::factory()->create()->id,
    ])->assertStatus(503);

    $events = queuedEvents('budget_gate_blocked');
    expect($events)->toHaveCount(1)
        ->and($events[0]['properties']['scope'])->toBe('global');
});

it('captures generation_completed with provider, duration and cost from the job', function () {
    config(['services.fashn.key' => 'test-key', 'analytics.cost_per_credit_usd' => 0.075]);
    Sleep::fake();
    Queue::fake();
    Storage::disk('r2')->buildTemporaryUrlsUsing(
        fn ($path, $expiration, $options = []) => 'https://r2.test/'.$path.'?X-Amz-Token=SECRETTOKEN'
    );
    Http::fake([
        '*/run' => Http::response(['id' => 'pred_1', 'error' => null], 200),
        '*/status/*' => Http::response([
            'id' => 'pred_1', 'status' => 'completed',
            'output' => ['https://cdn.fashn.test/look.png'], 'error' => null,
        ], 200),
        'https://cdn.fashn.test/*' => Http::response('LOOK-BYTES', 200),
    ]);

    $generation = Generation::factory()->create([
        'lead_id' => Lead::factory()->create()->id,
        'product_id' => Product::factory()->create()->id,
        'client_photo' => 'uploads/2026-08-31/abc.jpg',
        'status' => 'queued',
        'num_samples' => 1,
        'with_background' => false,
    ]);

    // Direct handle(): with the queue faked (to observe the analytics jobs),
    // dispatchSync would only RECORD the job instead of running it.
    runLookJob($generation);

    expect($generation->refresh()->status)->toBe('done');

    $events = queuedEvents('generation_completed');
    expect($events)->toHaveCount(1)
        ->and($events[0]['properties']['provider'])->toBe('fashn')
        ->and($events[0]['properties']['mode'])->toBe('single')
        ->and($events[0]['properties']['cost_usd'])->toBe(0.075)
        ->and($events[0]['properties']['duration_ms'])->toBeGreaterThanOrEqual(0);
});

it('captures generation_failed with a coarse code — never the raw error text', function () {
    config(['services.fashn.key' => 'test-key']);
    Sleep::fake();
    Queue::fake();
    Storage::disk('r2')->buildTemporaryUrlsUsing(
        fn ($path, $expiration, $options = []) => 'https://r2.test/'.$path.'?X-Amz-Token=SECRETTOKEN'
    );
    Http::fake([
        '*/run' => Http::response(['id' => 'pred_1', 'error' => null], 200),
        '*/status/*' => Http::response([
            'id' => 'pred_1', 'status' => 'failed',
            'error' => 'model exploded at https://r2.test/uploads/secret.jpg?X-Amz-Token=SECRET',
        ], 200),
    ]);

    $generation = Generation::factory()->create([
        'lead_id' => Lead::factory()->create()->id,
        'product_id' => Product::factory()->create()->id,
        'client_photo' => 'uploads/2026-08-31/abc.jpg',
        'status' => 'queued',
    ]);

    runLookJob($generation);

    expect($generation->refresh()->status)->toBe('failed');

    $events = queuedEvents('generation_failed');
    expect($events)->toHaveCount(1)
        ->and($events[0]['properties']['error_code'])->toBe('engine_failed')
        ->and($events[0]['properties']['stage'])->toBe('engine')
        ->and(json_encode($events[0]))->not->toContain('SECRET')
        ->and(json_encode($events[0]))->not->toContain('exploded');
});
