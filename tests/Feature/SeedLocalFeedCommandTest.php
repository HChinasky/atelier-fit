<?php

use App\Models\Product;

/*
 * The dev seeder command must populate the catalog through the REAL import
 * pipeline (no parallel logic) by reading the bundled fixture from disk — no
 * network at all — and must refuse to run in production. It shares the fixture
 * and therefore the same truth table as LocalCatalogFeedTest: 7 products land.
 */

it('seeds the dev catalog from the bundled fixture through the real pipeline', function () {
    expect(Product::count())->toBe(0);

    $this->artisan('feed:seed-local')
        ->expectsOutputToContain('via the real ImportFeedJob')
        ->assertSuccessful();

    // Same seven survivors as the integration test — proves it reused the real path.
    expect(Product::count())->toBe(7)
        ->and(Product::where('external_id', 'MANGO-DRESS-02')->exists())->toBeTrue()
        ->and(Product::where('external_id', 'BOUG-ABAYA-12')->exists())->toBeTrue();
});

it('is idempotent: running the seeder twice does not duplicate products', function () {
    $this->artisan('feed:seed-local')->assertSuccessful();
    $this->artisan('feed:seed-local')->assertSuccessful();

    expect(Product::count())->toBe(7);
});

it('refuses to run in production and writes nothing', function () {
    $this->app->detectEnvironment(fn () => 'production');

    $this->artisan('feed:seed-local')->assertFailed();

    expect(Product::count())->toBe(0);
});

it('fails cleanly when the given feed file does not exist', function () {
    $this->artisan('feed:seed-local', ['--file' => base_path('does/not/exist.xml')])
        ->assertFailed();

    expect(Product::count())->toBe(0);
});
