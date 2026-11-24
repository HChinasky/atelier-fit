<?php

use App\Console\Commands\SeedLocalFeedCommand;
use App\Jobs\ImportFeedJob;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * Integration test over the rich local catalog fixture, asserting its documented
 * "truth table". This runs the REAL XmlFeedParser through the REAL ImportFeedJob
 * (HTTP faked, as established) on the SAME file the dev seeder uses — so the
 * fixture, the seeder and this test can never quietly drift apart. It complements
 * the narrow unit tests in XmlFeedParserTest with one pass over realistic dirt.
 */

const LOCAL_FEED_URL = 'https://feed.test/local-catalog.xml';

/** Read the shared fixture and run it through the real import job over faked HTTP. */
function importLocalCatalog(): void
{
    $xml = file_get_contents(base_path(SeedLocalFeedCommand::DEFAULT_FIXTURE));

    config(['feed.url' => LOCAL_FEED_URL, 'feed.format' => 'xml']);
    Http::fake([LOCAL_FEED_URL => Http::response($xml, 200)]);

    ImportFeedJob::dispatchSync();
}

it('imports exactly the seven valid allowed-brand products from the truth table', function () {
    importLocalCatalog();

    expect(Product::count())->toBe(7)
        ->and(Product::whereIn('external_id', [
            'ZARA-SHIRT-01', 'MANGO-DRESS-02', 'MD-TROUSER-03', 'HM-TOP-04',
            'BOUG-ABAYA-12', 'MODA-HIJAB-14', 'MODA-SHAYLA-15',
        ])->count())->toBe(7);
});

it('drops foreign brands via the job allow-list (parsed, then filtered)', function () {
    importLocalCatalog();

    expect(Product::where('external_id', 'NIKE-LEG-05')->exists())->toBeFalse()
        ->and(Product::where('external_id', 'ADIDAS-JACKET-06')->exists())->toBeFalse();
});

it('skips parser-rejected items: foreign currency, unparseable price, unmappable category, missing fields', function () {
    importLocalCatalog();

    foreach (['ZARA-USD-07', 'ZARA-SALE-08', 'MANGO-BAG-09', 'ZARA-NOIMG-11'] as $id) {
        expect(Product::where('external_id', $id)->exists())->toBeFalse();
    }

    // The item with no <id> cannot exist under any external_id either.
    expect(Product::where('product_name', 'Mystery Knit')->exists())->toBeFalse();
});

it('imports all three FASHN categories among the survivors', function () {
    importLocalCatalog();

    // upper_body: shirt + top + the two head/shoulder coverings.
    expect(Product::where('category', 'upper_body')->count())->toBe(4)
        ->and(Product::where('category', 'lower_body')->count())->toBe(1)  // trousers
        // dresses: the maxi dress + the abaya.
        ->and(Product::where('category', 'dresses')->count())->toBe(2);
});

it('maps modest wear onto the engine\'s three regions: full-body to dresses, head coverings to upper_body', function () {
    importLocalCatalog();

    expect(Product::where('external_id', 'BOUG-ABAYA-12')->value('category'))->toBe('dresses')
        ->and(Product::where('external_id', 'MODA-HIJAB-14')->value('category'))->toBe('upper_body')
        ->and(Product::where('external_id', 'MODA-SHAYLA-15')->value('category'))->toBe('upper_body');
});

it('lets the modest-wear brands through the allow-list with their prices intact', function () {
    importLocalCatalog();

    $abaya = Product::where('external_id', 'BOUG-ABAYA-12')->firstOrFail();

    expect($abaya->brand_name)->toBe('Bouguessa')
        ->and($abaya->price_aed)->toBe('1850.00')                          // "1,850.00 AED"
        ->and($abaya->image_flat_url)->toBe('/dev-catalog/abaya-12.jpg')
        ->and(Product::where('brand_name', 'Modanisa')->count())->toBe(2);
});

it('parses prices exactly, including the AED-prefixed and thousands-separated forms', function () {
    importLocalCatalog();

    expect(Product::where('external_id', 'MANGO-DRESS-02')->value('price_aed'))->toBe('1499.00')
        ->and(Product::where('external_id', 'MD-TROUSER-03')->value('price_aed'))->toBe('249.00')
        ->and(Product::where('external_id', 'ZARA-SHIRT-01')->value('price_aed'))->toBe('149.00');
});

it('resolves the image through the field-name fallback (image_link / picture / photo)', function () {
    importLocalCatalog();

    expect(Product::where('external_id', 'ZARA-SHIRT-01')->value('image_flat_url'))
        ->toBe('/dev-catalog/zara-shirt-01.jpg')                            // image_link
        ->and(Product::where('external_id', 'MANGO-DRESS-02')->value('image_flat_url'))
        ->toBe('/dev-catalog/mango-dress-02.jpg')                           // <g:picture>
        ->and(Product::where('external_id', 'MD-TROUSER-03')->value('image_flat_url'))
        ->toBe('/dev-catalog/md-trouser-03.jpg');                           // <photo>
});

it('keeps the full structural snapshot in raw: both repeated tags and nesting survive', function () {
    importLocalCatalog();

    $raw = Product::where('external_id', 'MANGO-DRESS-02')->firstOrFail()->raw;

    // Repeated <g:size> tags are preserved as an ordered list — not collapsed.
    expect($raw['size'])->toBe(['M', 'L'])
        // Nested <g:shipping> block kept verbatim, distinct from the top-level price.
        ->and($raw['shipping']['country'])->toBe('AE')
        ->and($raw['shipping']['price'])->toBe('0.00 AED');
});

it('logs a warning for the foreign-currency price, the unparseable price, and the unmappable category', function () {
    Log::spy();

    importLocalCatalog();

    // ZARA-USD-07: foreign currency → "unparseable or non-AED price".
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context = []) => str_contains($message, 'unparseable or non-AED price')
            && ($context['external_id'] ?? null) === 'ZARA-USD-07')
        ->atLeast()->once();

    // ZARA-SALE-08: amount "Sale" → unparseable price. Its category ("Shorts")
    // IS mappable and price is checked first, so the logged reason is
    // unambiguously the price, not the category.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context = []) => str_contains($message, 'unparseable or non-AED price')
            && ($context['external_id'] ?? null) === 'ZARA-SALE-08')
        ->atLeast()->once();

    // MANGO-BAG-09: category maps to nothing.
    Log::shouldHaveReceived('warning')
        ->withArgs(fn ($message, $context = []) => str_contains($message, 'category not mappable')
            && ($context['external_id'] ?? null) === 'MANGO-BAG-09')
        ->atLeast()->once();
});

it('leaves fixture products unverified (flat_bg_verified = false), out of the catalog', function () {
    importLocalCatalog();

    expect(Product::where('flat_bg_verified', true)->count())->toBe(0);
});
