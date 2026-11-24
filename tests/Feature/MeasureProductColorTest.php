<?php

use App\Jobs\MeasureProductColor;
use App\Models\Product;
use App\Models\ProductSeasonMatch;
use App\Models\Season;
use App\Models\SeasonColor;
use App\Services\Color\ColorAnalysisClient;
use App\Services\Color\ColorAnalysisUnavailable;
use App\Services\Color\GarmentPaletteMatcher;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
 * MeasureProductColor: fetch the product's photo, have pose-service measure the
 * garment, keep the answer, judge it against the palettes. Both the shop and
 * the service are faked — no real request leaves the test.
 */

beforeEach(function () {
    config([
        'services.pose.url' => 'http://pose.test:8000',
        'services.pose.token' => '',
        'app.url' => 'http://app.test',
    ]);

    $season = Season::factory()->slug('deep_winter')->create();
    SeasonColor::factory()->for($season)->group(SeasonColor::GROUP_NEUTRALS)
        ->create(['lab_l' => 25.0, 'lab_a' => 5.0, 'lab_b' => -30.0]);
});

function garmentBody(string $status = 'ok', ?string $reason = null): array
{
    return ['result' => [
        'status' => $status,
        'reject_reason' => $reason,
        'method' => $status === 'ok' ? 'background' : null,
        'coverage' => 0.31,
        'colours' => $status === 'ok'
            ? [['L' => 25.0, 'a' => 5.0, 'b' => -30.0, 'C' => 30.4, 'h' => 279.5, 'hex' => '#1B2A4A', 'share' => 1.0]]
            : [],
        'algo_version' => 'garment-0.1.0',
    ]];
}

function measure(Product $product): void
{
    (new MeasureProductColor($product))->handle(
        app(ColorAnalysisClient::class),
        app(GarmentPaletteMatcher::class),
    );
}

it('fetches the shop photo, keeps the colours with the URL they came from, and matches them', function () {
    Http::fake([
        'shop.example/*' => Http::response('JPEG-BYTES', 200),
        'pose.test:8000/garment-colour' => Http::response(garmentBody()),
    ]);
    $product = Product::factory()->verified()->create(['image_flat_url' => 'https://shop.example/p/1.jpg']);

    measure($product);

    $product->refresh();
    expect($product->color_status)->toBe(Product::COLOR_OK)
        ->and($product->color['colours'][0]['hex'])->toBe('#1B2A4A')
        ->and($product->color['image_url'])->toBe('https://shop.example/p/1.jpg')
        ->and($product->color_measured_at)->not->toBeNull()
        ->and(ProductSeasonMatch::where('product_id', $product->id)->sole()->suits)->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->url() === 'http://pose.test:8000/garment-colour'
        && str_contains($request->body(), 'JPEG-BYTES'));
});

it('reads one of our own photos off the disk instead of requesting it from itself', function () {
    $dir = public_path('testing-garment');
    @mkdir($dir);
    file_put_contents($dir.'/dress.jpg', 'LOCAL-BYTES');
    Http::fake(['pose.test:8000/garment-colour' => Http::response(garmentBody())]);

    try {
        $relative = Product::factory()->verified()->create(['image_flat_url' => '/testing-garment/dress.jpg']);
        $ownHost = Product::factory()->verified()->create(['image_flat_url' => 'http://app.test/testing-garment/dress.jpg']);

        measure($relative);
        measure($ownHost);
    } finally {
        @unlink($dir.'/dress.jpg');
        @rmdir($dir);
    }

    expect($relative->fresh()->color_status)->toBe(Product::COLOR_OK)
        ->and($ownHost->fresh()->color_status)->toBe(Product::COLOR_OK);
    Http::assertSentCount(2);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'app.test'));
});

it('never reads outside public/ for a path with ../ in it', function () {
    Http::fake(['*' => Http::response('', 404)]);
    $product = Product::factory()->verified()->create(['image_flat_url' => '/../.env.example']);

    measure($product);

    expect($product->fresh()->color_status)->toBe(Product::COLOR_FAILED);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'garment-colour'));
});

it('keeps a photo the engine could not measure as rejected, with no matches', function () {
    Http::fake([
        'shop.example/*' => Http::response('JPEG-BYTES', 200),
        'pose.test:8000/*' => Http::response(garmentBody('rejected', 'no_garment')),
    ]);
    $product = Product::factory()->verified()->create(['image_flat_url' => 'https://shop.example/p/2.jpg']);

    measure($product);

    expect($product->fresh()->color_status)->toBe(Product::COLOR_REJECTED)
        ->and($product->fresh()->color['reject_reason'])->toBe('no_garment')
        ->and(ProductSeasonMatch::where('product_id', $product->id)->count())->toBe(0);
});

it('marks a photo the shop would not serve as failed, without calling the service', function () {
    Http::fake(['shop.example/*' => Http::response('', 404)]);
    $product = Product::factory()->verified()->measured()->create(['image_flat_url' => 'https://shop.example/gone.jpg']);
    ProductSeasonMatch::factory()->for($product)->forSeason('deep_winter')->create();

    measure($product);

    expect($product->fresh()->color_status)->toBe(Product::COLOR_FAILED)
        ->and(ProductSeasonMatch::where('product_id', $product->id)->count())->toBe(0);
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'pose.test'));
});

it('marks a service refusal (colour analysis off) as failed and does not retry it', function () {
    Http::fake([
        'shop.example/*' => Http::response('JPEG-BYTES', 200),
        'pose.test:8000/*' => Http::response(['detail' => 'Colour analysis is switched off.'], 503),
    ]);
    $product = Product::factory()->verified()->create(['image_flat_url' => 'https://shop.example/p/3.jpg']);

    measure($product);

    expect($product->fresh()->color_status)->toBe(Product::COLOR_FAILED)
        ->and($product->fresh()->color['error'])->toContain('switched off');
});

it('hands a service that is down back to the queue to retry, and marks failed when retries run out', function () {
    Http::fake([
        'shop.example/*' => Http::response('JPEG-BYTES', 200),
        'pose.test:8000/*' => Http::response('', 502),
    ]);
    $product = Product::factory()->verified()->create(['image_flat_url' => 'https://shop.example/p/4.jpg']);

    expect(fn () => measure($product))->toThrow(ColorAnalysisUnavailable::class);

    (new MeasureProductColor($product))->failed(new ColorAnalysisUnavailable('down'));
    expect($product->fresh()->color_status)->toBe(Product::COLOR_FAILED);
});

// --- the command -------------------------------------------------------------

it('queues only what needs measuring: new, re-photographed, or failed', function () {
    Queue::fake();
    $new = Product::factory()->create();
    $failed = Product::factory()->create(['color_status' => Product::COLOR_FAILED, 'color' => ['error' => 'x']]);
    $rephotographed = Product::factory()->measured()->create();
    $rephotographed->forceFill(['image_flat_url' => 'https://shop.example/new-photo.jpg'])->save();
    Product::factory()->measured()->create();
    Product::factory()->colourRejected()->create();

    $this->artisan('catalog:measure-colors')->expectsOutputToContain('3 product(s) queued')->assertSuccessful();

    Queue::assertPushed(MeasureProductColor::class, 3);
    foreach ([$new, $failed, $rephotographed] as $product) {
        Queue::assertPushed(MeasureProductColor::class, fn (MeasureProductColor $job) => $job->product->is($product)
            && $job->queue === config('color_analysis.queue'));
    }
});

it('queues every product with --all', function () {
    Queue::fake();
    Product::factory()->measured()->count(2)->create();
    Product::factory()->colourRejected()->create();

    $this->artisan('catalog:measure-colors --all')->assertSuccessful();

    Queue::assertPushed(MeasureProductColor::class, 3);
});

it('re-judges stored colours against the palettes with --rematch, measuring nothing', function () {
    Queue::fake();
    Http::fake();
    $product = Product::factory()->verified()->measured([[25.0, 5.0, -30.0, 1.0]])->create();

    $this->artisan('catalog:measure-colors --rematch')->expectsOutputToContain('1 product(s) re-matched')->assertSuccessful();

    expect(ProductSeasonMatch::where('product_id', $product->id)->sole()->suits)->toBeTrue();
    Queue::assertNothingPushed();
    Http::assertNothingSent();
});

it('is scheduled nightly, after the feed import', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'catalog:measure-colors'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 1 * * *');
});
