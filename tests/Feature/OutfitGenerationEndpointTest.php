<?php

use App\Jobs\GenerateOutfitJob;
use App\Models\Generation;
use App\Models\Lead;
use App\Models\PhotoConsent;
use App\Models\Product;
use App\Services\Pose\PoseDetector;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakePoseDetector;
use Tests\Support\PoseFixtures;

/*
 * POST /api/fitting-room/generate-outfit — the outfit (multi-garment) endpoint.
 * Gates run BEFORE any engine call: set composition, photo (Phase 9 once), budget
 * (the set counts as N generations). The job itself is faked off (Queue::fake) —
 * its chaining is covered by GenerateOutfitJobTest.
 */

const OUTFIT_PHOTO_KEY = 'uploads/2026-06-12/original.jpg';

function uploadOutfitPhoto(): void
{
    Storage::fake('r2');
    Storage::disk('r2')->put(OUTFIT_PHOTO_KEY, PoseFixtures::jpeg(1000, 1500));
}

/** @param array<int> $productIds */
function outfitPayload(array $productIds): array
{
    return [
        'lead_id' => Lead::factory()->create()->id,
        'product_ids' => $productIds,
        'photo_key' => OUTFIT_PHOTO_KEY,
        'consent_id' => PhotoConsent::factory()->create()->id,
    ];
}

beforeEach(function () {
    uploadOutfitPhoto();
    Storage::disk('r2')->buildTemporaryUrlsUsing(fn ($path, $exp, $opts = []) => 'https://r2.test/'.$path);
    Queue::fake();
});

it('queues an outfit chain for a valid lower + upper set', function () {
    $lower = Product::factory()->lowerBody()->create();
    $upper = Product::factory()->upperBody()->create();

    // Picked upper FIRST — the stored set must come back base-layer-first.
    $response = $this->postJson('/api/fitting-room/generate-outfit', outfitPayload([$upper->id, $lower->id]))
        ->assertStatus(202)
        ->assertJsonPath('status', 'queued')
        ->assertJsonPath('outfit.total', 2)
        ->assertJsonStructure(['generation_id', 'status_url', 'crop_box', 'before_url', 'outfit' => ['total']]);

    $generation = Generation::sole();
    // The "before" image is the processed photo the chain starts from (a signed
    // URL of the client_photo), so До matches Після.
    expect($response->json('before_url'))->toBe('https://r2.test/'.$generation->client_photo);
    expect($generation->isOutfit())->toBeTrue()
        ->and($generation->product_ids)->toBe([$lower->id, $upper->id])  // wearing order
        ->and($generation->product_id)->toBe($lower->id)                 // base layer
        ->and($generation->num_samples)->toBe(1)
        ->and($generation->status)->toBe('queued');

    // Dispatched on the dedicated outfit queue (its own long timeout / retry_after).
    Queue::assertPushedOn('outfits', GenerateOutfitJob::class);
});

it('counts the set as N generations against the budget (not one)', function () {
    config(['fitting_room.generation.global_cap' => 50]);
    $lower = Product::factory()->lowerBody()->create();
    $upper = Product::factory()->upperBody()->create();

    $this->postJson('/api/fitting-room/generate-outfit', outfitPayload([$lower->id, $upper->id]))
        ->assertStatus(202);

    // Two garments → two engine calls → two credits claimed against the global cap.
    expect((int) cache()->get('generation:global'))->toBe(2);
});

it('rejects a dress in a set with a localized 422 and never queues or spends', function () {
    $dress = Product::factory()->dresses()->create();
    $pants = Product::factory()->lowerBody()->create();

    $this->postJson('/api/fitting-room/generate-outfit', outfitPayload([$dress->id, $pants->id]))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'dress_must_be_solo')
        ->assertJsonPath('message', __('outfit.dress_must_be_solo', [], 'uk'));

    expect(Generation::count())->toBe(0)
        ->and((int) cache()->get('generation:global'))->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects two tops (duplicate layer)', function () {
    $a = Product::factory()->upperBody()->create();
    $b = Product::factory()->upperBody()->create();

    $this->postJson('/api/fitting-room/generate-outfit', outfitPayload([$a->id, $b->id]))
        ->assertStatus(422)
        ->assertJsonPath('reason', 'duplicate_layer');

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects more items than the configured cap before any gate', function () {
    config(['fitting_room.outfit.max_items' => 2]);
    $ids = [
        Product::factory()->lowerBody()->create()->id,
        Product::factory()->upperBody()->create()->id,
        Product::factory()->upperBody()->create()->id,
    ];

    $this->postJson('/api/fitting-room/generate-outfit', outfitPayload($ids))
        ->assertStatus(422);

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('does not start the chain when the budget cannot cover all N steps', function () {
    // Global cap of 1 cannot fit a 2-credit set → refuse up front, no generation.
    config(['fitting_room.generation.global_cap' => 1]);
    $lower = Product::factory()->lowerBody()->create();
    $upper = Product::factory()->upperBody()->create();

    $this->postJson('/api/fitting-room/generate-outfit', outfitPayload([$lower->id, $upper->id]))
        ->assertStatus(503)
        ->assertJsonPath('status', 'error');

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('counts the set against the per-user daily limit (a set is not a loophole)', function () {
    // Daily limit of 1 cannot fit a 2-unit set → 429, nothing queued.
    config(['fitting_room.generation.per_user_daily_limit' => 1]);
    $lower = Product::factory()->lowerBody()->create();
    $upper = Product::factory()->upperBody()->create();

    $this->postJson('/api/fitting-room/generate-outfit', outfitPayload([$lower->id, $upper->id]))
        ->assertStatus(429);

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('preprocesses the original photo once (Phase 9) and stores the processed copy', function () {
    // A full-body pose so preprocessing actually normalizes+crops (and replaces the
    // photo). The set reaches the legs, so the representative category is full-body.
    app()->instance(PoseDetector::class, FakePoseDetector::returning(PoseFixtures::fullBody(1000, 1500)));

    $lower = Product::factory()->lowerBody()->create();
    $upper = Product::factory()->upperBody()->create();

    $this->postJson('/api/fitting-room/generate-outfit', outfitPayload([$lower->id, $upper->id]))
        ->assertStatus(202);

    $generation = Generation::sole();

    // The processed photo sits under a new key and is what the chain starts
    // from; the upload is kept for the next look and recorded for erasure.
    expect($generation->client_photo)->not->toBe(OUTFIT_PHOTO_KEY)
        ->and($generation->source_photo)->toBe(OUTFIT_PHOTO_KEY)
        ->and(Storage::disk('r2')->exists(OUTFIT_PHOTO_KEY))->toBeTrue()
        ->and(Storage::disk('r2')->allFiles())->toHaveCount(2);
});
