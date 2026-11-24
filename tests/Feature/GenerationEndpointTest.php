<?php

use App\Jobs\GenerateLookJob;
use App\Models\Generation;
use App\Models\Lead;
use App\Models\PhotoConsent;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * POST /api/fitting-room/generate — budget gates BEFORE any FASHN call:
 * lead gate, global hard cap, per-user flood guard. FASHN is always mocked; a
 * rejected request must never spend a credit.
 */

function generationPayload(): array
{
    return [
        'lead_id' => Lead::factory()->create()->id,
        'product_id' => Product::factory()->create()->id,
        'photo_key' => 'uploads/2026-06-12/abc.jpg',
        // Consent gate: the endpoint refuses to process a photo without a recorded
        // consent for the current wording, so every valid payload carries one.
        'consent_id' => PhotoConsent::factory()->create()->id,
    ];
}

// The generate endpoint now reads the uploaded photo from R2 for preprocessing
// (9.5), so every test needs a fake disk. These budget-gate tests deliberately
// leave the key empty: with no bytes, preprocessing fails open and skips, so the
// gate behaviour they assert is exercised exactly as before.
beforeEach(fn () => Storage::fake('r2'));

it('queues a generation and calls the provider when there is a lead and budget', function () {
    config(['services.fashn.key' => 'test-key']);
    Storage::fake('r2');
    Storage::disk('r2')->buildTemporaryUrlsUsing(fn ($path, $exp, $opts = []) => 'https://r2.test/'.$path);
    Sleep::fake();
    Http::fake([
        '*/run' => Http::response(['id' => 'pred_ok'], 200),
        '*/status/*' => Http::response(['status' => 'completed', 'output' => ['https://cdn/look.png']], 200),
        // The finished look is pulled off the engine CDN into our own storage, so
        // the CDN has to be faked too or the job (correctly) fails.
        'https://cdn/*' => Http::response('LOOK-BYTES', 200),
    ]);

    $response = $this->postJson('/api/fitting-room/generate', generationPayload());

    $response->assertStatus(202)->assertJsonPath('status', 'queued');

    // Provider was actually called (the generation started end to end).
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/run'));
    expect(Generation::count())->toBe(1)
        ->and(Generation::sole()->lead_id)->not->toBeNull()
        ->and(Generation::sole()->status)->toBe('done');
});

it('returns a working signed status URL and the with_background flag', function () {
    config(['services.fashn.background.enabled' => true, 'services.fashn.num_samples' => 1]);
    Queue::fake();
    Http::fake();

    $response = $this->postJson('/api/fitting-room/generate', generationPayload())
        ->assertStatus(202)
        ->assertJsonStructure(['generation_id', 'with_background', 'status_url'])
        ->assertJsonPath('with_background', true);

    // The signed URL handed back actually validates and reads the status.
    $this->getJson($response->json('status_url'))
        ->assertOk()
        ->assertJsonPath('status', 'queued');
});

it('persists the modest config defaults: 2 looks, background off', function () {
    config(['services.fashn.num_samples' => 2, 'services.fashn.background.enabled' => false]);
    Queue::fake();
    Http::fake();

    $this->postJson('/api/fitting-room/generate', generationPayload())->assertStatus(202);

    $generation = Generation::sole();
    expect($generation->num_samples)->toBe(2)
        ->and($generation->with_background)->toBeFalse();
});

it('rejects a generation with no lead and never calls FASHN', function () {
    Http::fake();

    $payload = generationPayload();
    unset($payload['lead_id']);

    $this->postJson('/api/fitting-room/generate', $payload)->assertStatus(422);

    Http::assertNothingSent();
    expect(Generation::count())->toBe(0);
});

it('returns Ukrainian validation messages, like the rest of the product', function () {
    Http::fake();

    $payload = generationPayload();
    unset($payload['lead_id']);          // trigger the lead_id.required rule

    $this->postJson('/api/fitting-room/generate', $payload)
        ->assertStatus(422)
        ->assertJsonPath('errors.lead_id.0', 'Спочатку залиште контакт, щоб розпочати примірку.');

    Http::assertNothingSent();
});

it('rejects a generation whose lead does not exist', function () {
    Http::fake();

    $payload = generationPayload();
    $payload['lead_id'] = 999999;

    $this->postJson('/api/fitting-room/generate', $payload)->assertStatus(422);

    Http::assertNothingSent();
    expect(Generation::count())->toBe(0);
});

it('allows the per-user daily limit and rejects the next one (flood guard)', function () {
    config(['fitting_room.generation.per_user_daily_limit' => 3]);
    Queue::fake();
    Http::fake();

    $payload = generationPayload(); // same lead (contact) + same test IP each time

    for ($i = 0; $i < 3; $i++) {
        $this->postJson('/api/fitting-room/generate', $payload)->assertStatus(202);
    }

    // The 4th within the day is throttled.
    $this->postJson('/api/fitting-room/generate', $payload)
        ->assertStatus(429)
        ->assertJsonPath('status', 'error');

    Queue::assertPushed(GenerateLookJob::class, 3);
    Http::assertNothingSent();
    expect(Generation::count())->toBe(3);
});

it('refuses new generations once the global hard cap is reached, with a logged warning', function () {
    // One look, no background → cost 1, so a cap of 1 allows exactly one.
    config(['fitting_room.generation.global_cap' => 1, 'services.fashn.num_samples' => 1]);
    Queue::fake();
    Http::fake();
    Log::spy();

    // First generation consumes the only slot in the cap.
    $this->postJson('/api/fitting-room/generate', generationPayload())->assertStatus(202);

    // Second is refused — global cap reached.
    $this->postJson('/api/fitting-room/generate', generationPayload())
        ->assertStatus(503)
        ->assertJsonPath('status', 'error');

    Queue::assertPushed(GenerateLookJob::class, 1);
    Http::assertNothingSent();
    expect(Generation::count())->toBe(1);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context = []) => str_contains($message, 'global generation cap'))
        ->atLeast()->once();
});

it('checks the lead gate before the global cap (priority)', function () {
    config(['fitting_room.generation.global_cap' => 1, 'services.fashn.num_samples' => 1]);
    Queue::fake();
    Http::fake();

    // Consume the only slot so the cap is now reached.
    $this->postJson('/api/fitting-room/generate', generationPayload())->assertStatus(202);

    // A valid request is now refused by the cap...
    $this->postJson('/api/fitting-room/generate', generationPayload())->assertStatus(503);

    // ...but a request with no lead is rejected EARLIER, by the lead gate (422),
    // never reaching the cap check.
    $payload = generationPayload();
    unset($payload['lead_id']);
    $this->postJson('/api/fitting-room/generate', $payload)->assertStatus(422);

    Http::assertNothingSent();
});

it('counts FASHN credits against the global cap, not a flat one-per-generation', function () {
    // Cap of 4 CREDITS. With 1 look and no background (cost 1 each), four
    // generations fit before the cap is exhausted.
    config([
        'fitting_room.generation.global_cap' => 4,
        'fitting_room.generation.per_user_daily_limit' => 1000,
        'services.fashn.num_samples' => 1,
        'services.fashn.background.enabled' => false,
    ]);
    Queue::fake();
    Http::fake();

    for ($i = 0; $i < 4; $i++) {
        $this->postJson('/api/fitting-room/generate', generationPayload())->assertStatus(202);
    }

    // The 5th credit is over the cap.
    $this->postJson('/api/fitting-room/generate', generationPayload())->assertStatus(503);

    Queue::assertPushed(GenerateLookJob::class, 4);
    expect(Generation::count())->toBe(4);
});

it('refuses a 4-look + background generation (8 credits) where a 1-credit one would pass', function () {
    // Same cap of 4 credits. A single 4-look + background generation costs
    // 4 × 2 = 8 → refused outright, BEFORE any dispatch or FASHN call. A naive
    // "1 per generation" counter would have let this 8-credit request through.
    config([
        'fitting_room.generation.global_cap' => 4,
        'fitting_room.generation.per_user_daily_limit' => 1000,
        'services.fashn.num_samples' => 4,
        'services.fashn.background.enabled' => true,
    ]);
    Queue::fake();
    Http::fake();

    $this->postJson('/api/fitting-room/generate', generationPayload())
        ->assertStatus(503)
        ->assertJsonPath('status', 'error');

    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect(Generation::count())->toBe(0);
});
