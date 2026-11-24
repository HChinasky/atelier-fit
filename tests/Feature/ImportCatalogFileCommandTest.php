<?php

use App\Jobs\ImportFeedJob;
use App\Models\Product;

/*
 * catalog:import-file is the supported way to publish curated products into a
 * LIVE catalog, so its behaviour around existing data is the part that matters:
 * it must reuse the real pipeline, touch only the products named in the file,
 * and never quietly widen its blast radius. It is also the one importer allowed
 * in production, which is exactly why it asks first.
 */

/** Write a one-item feed to a temp file and return its path. */
function catalogFile(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'feed').'.xml';

    file_put_contents($path, <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">
      <channel>{$body}</channel>
    </rss>
    XML);

    return $path;
}

function catalogItem(string $id, string $brand = 'OneByOne', string $price = '999.00 AED'): string
{
    return <<<XML
      <item>
        <g:id>{$id}</g:id>
        <g:title>Test Dress {$id}</g:title>
        <g:brand>{$brand}</g:brand>
        <g:link>https://shop.test/{$id}</g:link>
        <g:image_link>https://cdn.test/{$id}.jpg</g:image_link>
        <g:price>{$price}</g:price>
        <g:product_type>Dresses</g:product_type>
      </item>
    XML;
}

it('imports a file through the real pipeline', function () {
    $path = catalogFile(catalogItem('CURATED-A'));

    $this->artisan('catalog:import-file', ['--file' => $path])
        ->expectsOutputToContain('via the real ImportFeedJob')
        ->assertSuccessful();

    $product = Product::where('external_id', 'CURATED-A')->firstOrFail();

    expect($product->price_aed)->toBe('999.00')
        ->and($product->category)->toBe('dresses');
});

it('is idempotent and updates listed products in place', function () {
    $this->artisan('catalog:import-file', ['--file' => catalogFile(catalogItem('CURATED-A'))])
        ->assertSuccessful();
    $this->artisan('catalog:import-file', ['--file' => catalogFile(catalogItem('CURATED-A', price: '1,250.00 AED'))])
        ->assertSuccessful();

    expect(Product::count())->toBe(1)
        ->and(Product::where('external_id', 'CURATED-A')->value('price_aed'))->toBe('1250.00');
});

it('leaves products that are not in the file alone', function () {
    $untouched = Product::factory()->create([
        'external_id' => 'NOT-IN-FILE',
        'flat_bg_verified' => true,
        'price_aed' => '500.00',
    ]);

    $this->artisan('catalog:import-file', ['--file' => catalogFile(catalogItem('CURATED-A'))])
        ->assertSuccessful();

    $untouched->refresh();

    expect($untouched->price_aed)->toBe('500.00')
        ->and($untouched->flat_bg_verified)->toBeTrue();
});

it('warns when the file names a brand the allow-list drops, instead of filtering it silently', function () {
    $path = catalogFile(catalogItem('CURATED-B', brand: 'Not In The List'));

    $this->artisan('catalog:import-file', ['--file' => $path])
        ->expectsOutputToContain('Not In The List')
        ->assertSuccessful();

    expect(Product::where('external_id', 'CURATED-B')->exists())->toBeFalse();
});

/** A one-item Amazon Product Advertising API file. */
function amazonCatalogFile(string $asin, string $brand = '<Brand>GORGLITTER</Brand>'): string
{
    $path = tempnam(sys_get_temp_dir(), 'feed').'.xml';

    file_put_contents($path, <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <ItemLookupResponse xmlns="http://webservices.amazon.com/AWSECommerceService/2013-08-01">
      <Items>
        <Item>
          <ASIN>{$asin}</ASIN>
          <DetailPageURL>https://www.amazon.ae/dp/{$asin}?tag=example-21</DetailPageURL>
          <LargeImage><URL>https://cdn.test/{$asin}.jpg</URL></LargeImage>
          <ItemAttributes>{$brand}<Title>Платье миди</Title></ItemAttributes>
          <Offers><Offer><OfferListing><Price>
            <Amount>12900</Amount><CurrencyCode>AED</CurrencyCode>
          </Price></OfferListing></Offer></Offers>
        </Item>
      </Items>
    </ItemLookupResponse>
    XML);

    return $path;
}

it('tells the Amazon shape from the Google Merchant shape by the root element', function () {
    $this->artisan('catalog:import-file', ['--file' => amazonCatalogFile('B0TESTASIN'), '--all-brands' => true])
        ->expectsOutputToContain('(amazon format)')
        ->assertSuccessful();

    $this->artisan('catalog:import-file', ['--file' => catalogFile(catalogItem('CURATED-A'))])
        ->expectsOutputToContain('(xml format)')
        ->assertSuccessful();

    $amazon = Product::where('external_id', 'B0TESTASIN')->firstOrFail();

    expect($amazon->price_aed)->toBe('129.00')
        ->and($amazon->category)->toBe('dresses')
        ->and(Product::count())->toBe(2);
});

it('warns about a filtered brand in the Amazon spelling too', function () {
    $this->artisan('catalog:import-file', ['--file' => amazonCatalogFile('B0TESTASIN')])
        ->expectsOutputToContain('GORGLITTER')
        ->assertSuccessful();

    expect(Product::count())->toBe(0);
});

it('lifts the brand allow-list for the run with --all-brands, and only for the run', function () {
    $path = catalogFile(catalogItem('CURATED-B', brand: 'Not In The List'));

    $this->artisan('catalog:import-file', ['--file' => $path, '--all-brands' => true])
        ->expectsOutputToContain('allow-list lifted')
        ->doesntExpectOutputToContain('will be filtered out')
        ->assertSuccessful();

    expect(Product::where('external_id', 'CURATED-B')->exists())->toBeTrue()
        ->and(config('feed.brands'))->toBe([ImportFeedJob::ANY_BRAND]);
});

it('runs in production only after the operator confirms', function () {
    $this->app->detectEnvironment(fn () => 'production');

    $path = catalogFile(catalogItem('CURATED-A'));

    $this->artisan('catalog:import-file', ['--file' => $path])
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertFailed();

    expect(Product::count())->toBe(0);

    $this->artisan('catalog:import-file', ['--file' => $path, '--force' => true])
        ->assertSuccessful();

    expect(Product::where('external_id', 'CURATED-A')->exists())->toBeTrue();
});

it('fails cleanly when the file does not exist', function () {
    $this->artisan('catalog:import-file', ['--file' => base_path('does/not/exist.xml')])
        ->assertFailed();

    expect(Product::count())->toBe(0);
});
