<?php

use App\Jobs\ImportFeedJob;
use App\Models\Product;
use App\Services\Feed\FeedParser;
use App\Services\Feed\ProductData;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeFeedParser;

const FEED_URL = 'https://feed.test/products.xml';

beforeEach(function () {
    config(['feed.url' => FEED_URL]);
});

/** Build a valid ProductData, overriding only what a test cares about. */
function productData(array $overrides = []): ProductData
{
    $defaults = [
        'externalId' => 'EXT-'.fake()->unique()->numerify('######'),
        'brandName' => 'Zara',
        'productName' => 'Linen Shirt',
        'priceAed' => '199.00',
        'imageFlatUrl' => 'https://cdn.test/flat.jpg',
        'shopUrl' => 'https://shop.test/p/1',
        'category' => 'upper_body',
        'raw' => ['source' => 'feed', 'sku' => 'abc'],
        'feedCategory' => 'Shirts',
    ];

    return new ProductData(...array_merge($defaults, $overrides));
}

/**
 * Mock the feed source (no real network), bind the fake parser, run the job.
 * $feedStatus lets a test simulate an unreachable/erroring source.
 */
function runImportWith(array $products, int $feedStatus = 200): void
{
    Http::fake([FEED_URL => Http::response('<raw>ignored by fake parser</raw>', $feedStatus)]);
    app()->instance(FeedParser::class, new FakeFeedParser($products));
    ImportFeedJob::dispatchSync();
}

it('imports parsed products into the database', function () {
    runImportWith([
        productData(['externalId' => 'EXT-1', 'productName' => 'Shirt']),
        productData(['externalId' => 'EXT-2', 'productName' => 'Trousers', 'category' => 'lower_body']),
    ]);

    expect(Product::count())->toBe(2);

    $shirt = Product::where('external_id', 'EXT-1')->firstOrFail();
    expect($shirt->product_name)->toBe('Shirt')
        ->and($shirt->category)->toBe('upper_body')
        ->and($shirt->raw)->toBeArray()
        ->and($shirt->raw['source'])->toBe('feed')
        ->and($shirt->feed_category)->toBe('Shirts')
        // extension point default: not verified yet (kept out of the catalog)
        ->and($shirt->flat_bg_verified)->toBeFalse();
});

it('is idempotent: re-running updates in place without duplicating', function () {
    runImportWith([productData(['externalId' => 'EXT-DUP', 'priceAed' => '100.00'])]);
    expect(Product::count())->toBe(1);

    // same external_id, changed price → update, not insert
    runImportWith([productData(['externalId' => 'EXT-DUP', 'priceAed' => '150.00'])]);

    expect(Product::count())->toBe(1)
        ->and(Product::where('external_id', 'EXT-DUP')->value('price_aed'))->toBe('150.00');
});

it('filters out products whose brand is not in the configured allow-list', function () {
    runImportWith([
        productData(['externalId' => 'EXT-Z', 'brandName' => 'Zara']),
        productData(['externalId' => 'EXT-N', 'brandName' => 'Nike']),
    ]);

    expect(Product::count())->toBe(1)
        ->and(Product::where('external_id', 'EXT-Z')->exists())->toBeTrue()
        ->and(Product::where('external_id', 'EXT-N')->exists())->toBeFalse();
});

it('lets every brand through when the allow-list holds the * entry', function () {
    config(['feed.brands' => [ImportFeedJob::ANY_BRAND]]);

    runImportWith([
        productData(['externalId' => 'EXT-Z', 'brandName' => 'Zara']),
        productData(['externalId' => 'EXT-N', 'brandName' => 'Nike']),
    ]);

    expect(Product::count())->toBe(2);
});

it('matches allowed brands case-insensitively', function () {
    runImportWith([productData(['externalId' => 'EXT-LC', 'brandName' => 'zara'])]);

    expect(Product::where('external_id', 'EXT-LC')->exists())->toBeTrue();
});

it('skips a malformed item, logs it, imports the rest, and does not fail', function () {
    Log::spy();

    runImportWith([
        productData(['externalId' => 'EXT-OK1']),
        productData(['externalId' => '', 'productName' => 'no id']),          // invalid: empty external_id
        productData(['externalId' => 'EXT-BADCAT', 'category' => 'bags']),    // invalid: category not allowed
        productData(['externalId' => 'EXT-OK2']),
    ]);

    // valid ones imported, bad ones skipped, job completed
    expect(Product::count())->toBe(2)
        ->and(Product::where('external_id', 'EXT-OK1')->exists())->toBeTrue()
        ->and(Product::where('external_id', 'EXT-OK2')->exists())->toBeTrue()
        ->and(Product::where('external_id', 'EXT-BADCAT')->exists())->toBeFalse();

    Log::shouldHaveReceived('warning')->atLeast()->once();
});

it('fails (and does not import) when the feed source is unreachable', function () {
    Http::fake([FEED_URL => Http::response('upstream error', 500)]);
    app()->instance(FeedParser::class, new FakeFeedParser([productData()]));

    $caught = null;
    try {
        ImportFeedJob::dispatchSync();
    } catch (Throwable $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull()
        ->and(Product::count())->toBe(0);
});

it('runs on the queue, not synchronously by design', function () {
    expect(is_subclass_of(ImportFeedJob::class, ShouldQueue::class))->toBeTrue();
});

it('is registered in the schedule to run daily', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('import-feed')
        ->assertExitCode(0);
});

/** The scheduled import-feed event. */
function importFeedEvent(): Event
{
    return collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => $event->description === 'import-feed');
}

it('runs the scheduled import once a feed URL is configured', function () {
    expect(importFeedEvent()->filtersPass(app()))->toBeTrue();
});

it('skips the scheduled import while no feed URL is configured, instead of failing on an empty one', function (?string $url) {
    // Production while the partner network is in moderation: FEED_URL is empty
    // and the nightly fetch of "" logged an error every night.
    config(['feed.url' => $url]);

    expect(importFeedEvent()->filtersPass(app()))->toBeFalse();
})->with([
    'unset' => [null],
    'empty' => [''],
]);
