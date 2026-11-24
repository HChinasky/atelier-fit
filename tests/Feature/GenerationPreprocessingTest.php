<?php

use App\Models\Generation;
use App\Models\Lead;
use App\Models\PhotoConsent;
use App\Models\Product;
use App\Services\Pose\PoseDetector;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Tests\Support\FakePoseDetector;
use Tests\Support\PoseFixtures;

/*
 * Preprocessing wired into POST /api/fitting-room/generate (9.5). The photo gate
 * sits between the lead gate and the budget gate: an unsuitable photo is rejected
 * before any FASHN call or credit. Pose is always faked; FASHN is always mocked.
 */

const ORIGINAL_KEY = 'uploads/2026-06-12/original.jpg';

function bindPose(PoseDetector $detector): void
{
    app()->instance(PoseDetector::class, $detector);
}

function uploadOriginal(int $w = 1000, int $h = 1500): void
{
    Storage::fake('r2');
    Storage::disk('r2')->put(ORIGINAL_KEY, PoseFixtures::jpeg($w, $h));
}

function fashnFakeOk(): void
{
    Storage::disk('r2')->buildTemporaryUrlsUsing(fn ($path, $exp, $opts = []) => 'https://r2.test/'.$path);
    Sleep::fake();
    Http::fake([
        '*/run' => Http::response(['id' => 'pred_ok'], 200),
        '*/status/*' => Http::response(['status' => 'completed', 'output' => ['https://cdn/look.png']], 200),
        // The finished look is copied off the engine CDN into our own storage.
        'https://cdn/*' => Http::response('LOOK-BYTES', 200),
    ]);
}

function generatePayload(Product $product): array
{
    return [
        'lead_id' => Lead::factory()->create()->id,
        'product_id' => $product->id,
        'photo_key' => ORIGINAL_KEY,
        'consent_id' => PhotoConsent::factory()->create()->id,
    ];
}

// These tests assert the processed photo is the narrow person crop, so the 9.6
// output aspect-fit (which reframes it to 3:4) is off here; it has its own tests.
beforeEach(fn () => config(['photo.output.aspect' => null]));

it('processes a suitable photo and sends the PROCESSED photo to FASHN, keeping the upload', function () {
    config(['services.fashn.key' => 'test-key']);
    uploadOriginal(1000, 1500);
    fashnFakeOk();
    bindPose(FakePoseDetector::returning(PoseFixtures::fullBody(1000, 1500)));

    $product = Product::factory()->ofCategory('dresses')->create();

    $response = $this->postJson('/api/fitting-room/generate', generatePayload($product))
        ->assertStatus(202)
        ->assertJsonPath('status', 'queued');

    // The crop region travels back to the client (for the Before/After slider) as
    // fractions of the photo — geometry only, normalized to 0..1.
    $box = $response->json('crop_box');
    expect($box)->toBeArray()
        ->and($box)->toHaveKeys(['x', 'y', 'width', 'height', 'aspect'])
        ->and($box['width'])->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0)
        ->and($box['height'])->toBeGreaterThan(0.0)->toBeLessThanOrEqual(1.0);

    $generation = Generation::sole();

    // The "before" image handed to the slider is the PROCESSED photo (the exact
    // engine input), not the raw upload — a signed URL of the processed key.
    expect($response->json('before_url'))->toBe('https://r2.test/'.$generation->client_photo);

    // The stored photo was replaced by a processed one under a NEW key...
    expect($generation->client_photo)->not->toBe(ORIGINAL_KEY)
        ->and($generation->status)->toBe('done');

    // ...the upload is kept for the next look and recorded for erasure. Three
    // objects, and only three: the upload, the processed photo, and the finished
    // look now held in our own storage rather than on the engine CDN.
    expect(Storage::disk('r2')->exists(ORIGINAL_KEY))->toBeTrue()
        ->and($generation->source_photo)->toBe(ORIGINAL_KEY)
        ->and(Storage::disk('r2')->allFiles())->toHaveCount(3)
        ->and(collect(Storage::disk('r2')->allFiles())->filter(fn ($f) => str_contains($f, '-look-')))->toHaveCount(1);

    // The processed image is real and cropped (smaller than the 1000-wide source).
    [$w] = getimagesizefromstring(Storage::disk('r2')->get($generation->client_photo));
    expect($w)->toBeLessThan(1000);

    // FASHN received the PROCESSED key, not the original.
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/run')
        && str_ends_with(data_get($request->data(), 'inputs.model_image'), $generation->client_photo));
});

it('keeps the photo when the budget gate refuses, so the next try still has it', function () {
    // Bug: the processed copy was stored (and the original deleted) before the
    // gate. A refusal — here the global cap, the same path as the member
    // paywall — left no generation but a deleted photo whose key the page still
    // held, so the following try failed at FASHN ("could not be downloaded").
    config(['fitting_room.generation.global_cap' => 1, 'services.fashn.num_samples' => 2]);
    uploadOriginal(1000, 1500);
    Queue::fake();
    Http::fake();
    bindPose(FakePoseDetector::returning(PoseFixtures::fullBody(1000, 1500)));

    $product = Product::factory()->ofCategory('dresses')->create();

    $this->postJson('/api/fitting-room/generate', generatePayload($product))
        ->assertStatus(503);

    // The upload is untouched and no orphaned processed copy was left behind.
    expect(Generation::count())->toBe(0)
        ->and(Storage::disk('r2')->exists(ORIGINAL_KEY))->toBeTrue()
        ->and(Storage::disk('r2')->allFiles())->toBe([ORIGINAL_KEY]);
});

it('makes a second look from the same upload, each processed from the original', function () {
    // Bug: the first look deleted the upload while the page kept its key, so
    // "Додати образ" on the same photo failed at FASHN.
    config(['services.fashn.key' => 'test-key']);
    uploadOriginal(1000, 1500);
    fashnFakeOk();
    bindPose(FakePoseDetector::returning(PoseFixtures::fullBody(1000, 1500)));

    $product = Product::factory()->ofCategory('dresses')->create();
    $payload = generatePayload($product);

    $this->postJson('/api/fitting-room/generate', $payload)->assertStatus(202);
    $this->postJson('/api/fitting-room/generate', $payload)->assertStatus(202);

    [$first, $second] = Generation::orderBy('id')->get()->all();

    expect($first->status)->toBe('done')
        ->and($second->status)->toBe('done')
        ->and($second->source_photo)->toBe(ORIGINAL_KEY)
        ->and($second->client_photo)->not->toBe($first->client_photo)
        ->and(Storage::disk('r2')->exists(ORIGINAL_KEY))->toBeTrue();
});

it('rejects an unsuitable photo BEFORE FASHN, charging no credit', function () {
    uploadOriginal(1000, 1500);
    Http::fake();
    bindPose(FakePoseDetector::returning(PoseFixtures::waistUp(1000, 1500)));

    $product = Product::factory()->ofCategory('dresses')->create();

    $this->postJson('/api/fitting-room/generate', generatePayload($product))
        ->assertStatus(422)
        ->assertJsonPath('status', 'error')
        ->assertJsonPath('reason', 'not_full_body')
        // Message is Ukrainian (product language), regardless of the app locale.
        ->assertJsonPath('message', __('photo_validation.not_full_body', [], 'uk'));

    // No generation, no dispatch, no FASHN call — and the original photo is untouched.
    expect(Generation::count())->toBe(0)
        ->and(Storage::disk('r2')->exists(ORIGINAL_KEY))->toBeTrue();
    Http::assertNothingSent();
});

it('does not consume a budget credit for a rejected photo', function () {
    // Global cap of 1 credit. A rejected photo must not spend it: the next, valid
    // request still succeeds.
    config(['fitting_room.generation.global_cap' => 1, 'services.fashn.num_samples' => 1, 'services.fashn.key' => 'test-key']);
    uploadOriginal(1000, 1500);
    fashnFakeOk();

    $product = Product::factory()->ofCategory('dresses')->create();

    // Bad photo -> rejected, no credit spent.
    bindPose(FakePoseDetector::returning(PoseFixtures::waistUp(1000, 1500)));
    $this->postJson('/api/fitting-room/generate', generatePayload($product))->assertStatus(422);

    // The single cap slot is still free, so a good photo goes through.
    bindPose(FakePoseDetector::returning(PoseFixtures::fullBody(1000, 1500)));
    $this->postJson('/api/fitting-room/generate', generatePayload($product))->assertStatus(202);

    expect(Generation::count())->toBe(1);
});

it('lets the SAME waist-up photo through for a top (category flows end to end)', function () {
    config(['services.fashn.key' => 'test-key']);
    uploadOriginal(1000, 1500);
    fashnFakeOk();
    bindPose(FakePoseDetector::returning(PoseFixtures::waistUp(1000, 1500)));

    $product = Product::factory()->ofCategory('upper_body')->create();

    $this->postJson('/api/fitting-room/generate', generatePayload($product))->assertStatus(202);

    expect(Generation::count())->toBe(1);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/run'));
});

it('proceeds without preprocessing when pose is down and the policy is fail-open', function () {
    config(['services.pose.failure_mode' => 'fail_open', 'services.fashn.key' => 'test-key']);
    uploadOriginal(1000, 1500);
    fashnFakeOk();
    bindPose(FakePoseDetector::throwing());

    $product = Product::factory()->ofCategory('dresses')->create();

    $response = $this->postJson('/api/fitting-room/generate', generatePayload($product))->assertStatus(202);

    // No preprocessing ran, so there is no crop region to frame against.
    expect($response->json('crop_box'))->toBeNull();
    // The "before" is still the actual engine input — here the unprocessed original.
    expect($response->json('before_url'))->toBe('https://r2.test/'.ORIGINAL_KEY);

    $generation = Generation::sole();

    // Funnel works; the ORIGINAL (unprocessed) photo is used and reaches FASHN.
    expect($generation->client_photo)->toBe(ORIGINAL_KEY)
        ->and(Storage::disk('r2')->exists(ORIGINAL_KEY))->toBeTrue();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/run'));
});

it('blocks generation when pose is down and the policy is fail-closed', function () {
    config(['services.pose.failure_mode' => 'fail_closed']);
    uploadOriginal(1000, 1500);
    Http::fake();
    Queue::fake();
    bindPose(FakePoseDetector::throwing());

    $product = Product::factory()->ofCategory('dresses')->create();

    $this->postJson('/api/fitting-room/generate', generatePayload($product))
        ->assertStatus(503)
        ->assertJsonPath('status', 'error');

    expect(Generation::count())->toBe(0);
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});
