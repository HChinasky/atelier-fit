<?php

use App\Jobs\ImportFeedJob;
use App\Models\Product;
use App\Services\Feed\FeedParser;
use App\Services\Feed\XmlFeedParser;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * End-to-end of the import slice over the REAL parser (no FakeFeedParser): the
 * job fetches a faked HTTP body, the container resolves XmlFeedParser by format,
 * and products land in the test DB through the genuine brand filter + upsert.
 */

const REAL_FEED_URL = 'https://feed.test/google-merchant.xml';

beforeEach(function () {
    config(['feed.url' => REAL_FEED_URL, 'feed.format' => 'xml']);
});

function importFixture(string $name): void
{
    $xml = file_get_contents(__DIR__.'/../Fixtures/feed/'.$name);
    Http::fake([REAL_FEED_URL => Http::response($xml, 200)]);
    ImportFeedJob::dispatchSync();
}

it('selects the Xml parser for the xml format', function () {
    expect(app(FeedParser::class))->toBeInstanceOf(XmlFeedParser::class);
});

it('imports only valid, allowed-brand products parsed from a real Google Merchant feed', function () {
    Log::spy();

    importFixture('import_job_feed.xml');

    // ZARA-100 (shirt) and MANGO-200 (dress) survive; Nike is brand-filtered,
    // the USD item and the un-mappable handbag are dropped by the parser.
    expect(Product::count())->toBe(2)
        ->and(Product::where('external_id', 'ZARA-100')->exists())->toBeTrue()
        ->and(Product::where('external_id', 'MANGO-200')->exists())->toBeTrue()
        ->and(Product::where('external_id', 'NIKE-300')->exists())->toBeFalse()
        ->and(Product::where('external_id', 'ZARA-400')->exists())->toBeFalse()
        ->and(Product::where('external_id', 'MANGO-500')->exists())->toBeFalse();
});

it('persists the parsed fields, including the thousands-separated price as exact decimal', function () {
    importFixture('import_job_feed.xml');

    $dress = Product::where('external_id', 'MANGO-200')->firstOrFail();
    expect($dress->brand_name)->toBe('Mango')
        ->and($dress->product_name)->toBe('Maxi Dress')
        ->and($dress->category)->toBe('dresses')
        ->and($dress->price_aed)->toBe('1250.00')
        ->and($dress->image_flat_url)->toBe('https://cdn.test/mango/200.jpg')
        ->and($dress->shop_url)->toBe('https://shop.test/mango/200')
        // full item kept as jsonb
        ->and($dress->raw)->toBeArray()
        ->and($dress->raw['title'])->toBe('Maxi Dress');
});

it('is idempotent over the real parser: re-importing updates in place', function () {
    importFixture('import_job_feed.xml');
    importFixture('import_job_feed.xml');

    expect(Product::count())->toBe(2);
});
