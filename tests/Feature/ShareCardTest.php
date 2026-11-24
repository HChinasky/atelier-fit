<?php

use App\Jobs\GenerateLookJob;
use App\Models\Generation;
use App\Models\Lead;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Sleep;

/*
 * Этап B1 — branded share cards. Composed after a successful generation from
 * the FINAL look only, stored in the SAME private prefix as the result (so the
 * 72h purge reaps them), served exclusively through short-lived signed URLs
 * behind the signed status route — no public URL to a card exists anywhere.
 */

beforeEach(function () {
    config(['services.fashn.key' => 'test-key']);
    Storage::fake('r2');
    Storage::disk('r2')->buildTemporaryUrlsUsing(
        fn ($path, $expiration, $options = []) => 'https://r2.test/'.$path.'?X-Amz-Token=SECRETTOKEN'
    );
    Sleep::fake();
});

/** A real decodable PNG, standing in for the engine's generated look. */
function lookPngBytes(int $width = 600, int $height = 800): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, 210, 190, 170));
    ob_start();
    imagepng($image);

    return (string) ob_get_clean();
}

function cardGeneration(?string $lookBytes = null): Generation
{
    Http::fake([
        '*/run' => Http::response(['id' => 'pred_1', 'error' => null], 200),
        '*/status/*' => Http::response([
            'id' => 'pred_1', 'status' => 'completed',
            'output' => ['https://cdn.fashn.test/look.png'], 'error' => null,
        ], 200),
        'https://cdn.fashn.test/*' => Http::response($lookBytes ?? lookPngBytes(), 200),
    ]);

    $product = Product::factory()->create([
        'brand_name' => 'Nia', 'product_name' => 'Linen Dress', 'price_aed' => 590,
    ]);

    return Generation::factory()->create([
        'lead_id' => Lead::factory()->create()->id,
        'product_id' => $product->id,
        'client_photo' => 'uploads/2026-08-31/abc.jpg',
        'status' => 'queued',
        'num_samples' => 1,
    ]);
}

it('composes a feed (3:4) and a story (9:16) card next to the result after success', function () {
    $generation = cardGeneration();

    GenerateLookJob::dispatchSync($generation);

    $keys = $generation->refresh()->share_keys;

    expect($generation->status)->toBe('done')
        ->and($keys)->toHaveKeys(['feed', 'story']);

    foreach (['feed' => [1080, 1440], 'story' => [1080, 1920]] as $format => [$w, $h]) {
        $key = $keys[$format];

        // Same private date partition as the client photo — the purge's beat.
        expect($key)->toStartWith('uploads/2026-08-31/');
        Storage::disk('r2')->assertExists($key);

        $card = imagecreatefromstring((string) Storage::disk('r2')->get($key));
        expect(imagesx($card))->toBe($w)
            ->and(imagesy($card))->toBe($h);
    }
});

it('keeps the generation successful even when card composition fails', function () {
    // Undecodable "image" from the engine: the result pipeline stores it fine,
    // but the composer cannot read it — the try-on must still complete.
    $generation = cardGeneration('NOT-AN-IMAGE');

    GenerateLookJob::dispatchSync($generation);

    expect($generation->refresh()->status)->toBe('done')
        ->and($generation->share_keys)->toBeNull();
});

it('serves the cards only through the signed status route, as short-lived urls', function () {
    $generation = cardGeneration();
    GenerateLookJob::dispatchSync($generation);

    $signed = URL::temporarySignedRoute(
        'fitting-room.generation.status',
        now()->addHour(),
        ['generation' => $generation->id],
    );

    $share = $this->getJson($signed)->assertOk()->json('share');

    expect($share['feed'])->toStartWith('https://r2.test/uploads/2026-08-31/')
        ->and($share['feed'])->toContain('X-Amz-Token=')
        ->and($share['story'])->toContain('-share-story');

    // And WITHOUT a valid signature there is no way in at all.
    $this->getJson('/api/fitting-room/generation/'.$generation->id)->assertForbidden();
});

it('registers no public route that could serve a share card', function () {
    $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri());

    expect($uris->filter(fn (string $uri) => str_contains($uri, 'share'))->values()->all())->toBe([]);
});

it('is reaped by the expired-photo purge together with the result', function () {
    config(['privacy.photo_hard_delete_hours' => 60]);

    Storage::disk('r2')->put('uploads/2026-08-20/x-share-feed.jpg', 'CARD');
    Storage::disk('r2')->put('uploads/2026-08-20/x-share-story.jpg', 'CARD');
    foreach (Storage::disk('r2')->allFiles('uploads') as $file) {
        touch(Storage::disk('r2')->path($file), now()->subHours(70)->getTimestamp());
    }

    $this->artisan('app:purge-expired-photos')->assertSuccessful();

    expect(Storage::disk('r2')->allFiles('uploads'))->toBeEmpty();
});

it('can be disabled by config without touching the generation', function () {
    config(['share.enabled' => false]);

    $generation = cardGeneration();
    GenerateLookJob::dispatchSync($generation);

    expect($generation->refresh()->status)->toBe('done')
        ->and($generation->share_keys)->toBeNull();
});
