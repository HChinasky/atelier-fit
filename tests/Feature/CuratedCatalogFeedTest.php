<?php

use App\Console\Commands\ImportCatalogFileCommand;
use App\Models\Product;

/*
 * The curated catalog is the products the LIVE site sells while the partner
 * feed is in moderation, shipped in git as XML and published with
 * catalog:import-file. Unlike local_catalog_feed.xml it carries no deliberate
 * dirt: every item must survive the real pipeline, so a typo in a price, a
 * category that maps to nothing, or a brand missing from the allow-list is a
 * broken catalog — this test is what catches that before a deploy does.
 *
 * It asserts the file's contents (names, brands, prices, ids) because those are
 * the product data the customer sees and the buy button uses; a silent edit to
 * any of them should fail here rather than surface in production.
 */

/** Every product the curated catalog is expected to publish. */
function curatedCatalog(): array
{
    return [
        'ONEBYONE-DRESS-MAXI-BROWN-CRINKLE' => [
            'brand' => 'OneByOne',
            'name' => 'Сукня максі коричневого кольору жатка',
            'price' => '1299.00',
            'image' => 'sample-dress-01.jpg',
        ],
        'ONEBYONE-DRESS-MAXI-RED-DEEP-V' => [
            'brand' => 'OneByOne',
            'name' => 'Сукня максі червоного кольору з глибоким вирізом',
            'price' => '1299.00',
            'image' => 'sample-dress-02.jpg',
        ],
        'ONEBYONE-DRESS-MIDI-WHITE-LACE' => [
            'brand' => 'OneByOne',
            'name' => 'Сукня міді комбінована білого кольору на бретелях',
            'price' => '1299.00',
            'image' => 'sample-dress-05.jpg',
        ],
        'ONEBYONE-DRESS-MIDI-BLACK-CRINKLE-OPEN-BACK' => [
            'brand' => 'OneByOne',
            'name' => 'Сукня міді чорного кольору жатка з відкритою спинкою',
            'price' => '1299.00',
            'image' => 'sample-dress-03.jpg',
        ],
        'ONEBYONE-DRESS-MIDI-BLACK-LACE' => [
            'brand' => 'OneByOne',
            'name' => 'Сукня міді чорного кольору з мережива',
            'price' => '1299.00',
            'image' => 'sample-dress-04.jpg',
        ],
        'ZIM-DRESS-VALIANT-FLORAL-CORSET' => [
            'brand' => 'Zimmermann',
            'name' => 'Valiant Floral Corset Picnic Dress',
            'price' => '5200.00',
            'image' => 'sample-dress-06.jpg',
        ],
        'ZIM-DRESS-VALIANT-LACE-TRIM-MIDI' => [
            'brand' => 'Zimmermann',
            'name' => 'Valiant Lace Trim Midi Dress',
            'price' => '4900.00',
            'image' => 'sample-dress-07.jpg',
        ],
    ];
}

it('imports every curated product: nothing in this feed is meant to be skipped', function () {
    $this->artisan('catalog:import-file')->assertSuccessful();

    expect(Product::count())->toBe(count(curatedCatalog()))
        ->and(Product::pluck('external_id')->sort()->values()->all())
        ->toBe(collect(curatedCatalog())->keys()->sort()->values()->all());
});

it('carries the names, brands and AED prices the customer is shown', function () {
    $this->artisan('catalog:import-file')->assertSuccessful();

    foreach (curatedCatalog() as $externalId => $expected) {
        $product = Product::where('external_id', $externalId)->firstOrFail();

        expect($product->brand_name)->toBe($expected['brand'])
            ->and($product->product_name)->toBe($expected['name'])
            ->and($product->price_aed)->toBe($expected['price'])
            // Every curated item is a dress; the feed says so via product_type.
            ->and($product->category)->toBe('dresses');
    }
});

it('points every product at a photo that actually exists under public/dev-catalog', function () {
    $this->artisan('catalog:import-file')->assertSuccessful();

    foreach (curatedCatalog() as $externalId => $expected) {
        $url = Product::where('external_id', $externalId)->value('image_flat_url');

        // Absolute on purpose: FASHN fetches this URL from the outside, so a
        // site-relative path would be unreachable (see the feed's header).
        expect($url)->toStartWith('https://')
            ->and($url)->toEndWith('/dev-catalog/'.$expected['image'])
            ->and(public_path('dev-catalog/'.$expected['image']))->toBeFile();
    }
});

it('gives every product a buy-button destination', function () {
    $this->artisan('catalog:import-file')->assertSuccessful();

    Product::all()->each(function (Product $product) {
        expect($product->shop_url)->toStartWith('https://');
    });
});

it('lets both curated brands through the configured allow-list', function () {
    $allowed = collect(config('feed.brands'))->map(fn ($b) => mb_strtolower($b));

    expect($allowed)->toContain('onebyone')
        ->and($allowed)->toContain('zimmermann');
});

it('leaves curated products unpublished until a human verifies the photos', function () {
    $this->artisan('catalog:import-file')->assertSuccessful();

    expect(Product::where('flat_bg_verified', true)->count())->toBe(0);
});

it('publishes the curated catalog once catalog:verify names the products', function () {
    $this->artisan('catalog:import-file')->assertSuccessful();
    $this->artisan('catalog:verify', ['external_id' => array_keys(curatedCatalog())])
        ->assertSuccessful();

    $this->getJson('/api/catalog')
        ->assertOk()
        ->assertJsonCount(count(curatedCatalog()), 'data');
});

it('ships the curated feed at the path the command defaults to', function () {
    expect(base_path(ImportCatalogFileCommand::CURATED_CATALOG))->toBeFile();
});
