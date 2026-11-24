<?php

use App\Models\Product;

/*
 * GET /api/catalog. Production contract: only flat_bg_verified products are ever
 * returned; a dev-only flag may additionally surface unverified products, but
 * never in production. Data is prepared through the Product factory states.
 */

it('returns verified products and hides unverified ones (production contract)', function () {
    Product::factory()->verified()->create(['product_name' => 'Visible Shirt']);
    Product::factory()->unverified()->create(['product_name' => 'Hidden Shirt']);

    $response = $this->getJson('/api/catalog');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product_name', 'Visible Shirt');
});

it('exposes only the allow-listed fields and never leaks raw or internal columns', function () {
    Product::factory()->verified()->create();

    $item = $this->getJson('/api/catalog')->json('data.0');

    expect(array_keys($item))->toEqualCanonicalizing([
        'id', 'external_id', 'brand_name', 'product_name',
        'price_aed', 'image_flat_url', 'category', 'shop_url',
    ]);

    // The jsonb feed object and operational columns must not appear.
    expect($item)->not->toHaveKey('raw')
        ->and($item)->not->toHaveKey('flat_bg_verified')
        ->and($item)->not->toHaveKey('created_at')
        ->and($item)->not->toHaveKey('updated_at');
});

it('shows unverified products in a dev environment when the flag is enabled', function () {
    // The testing environment is not production, so the dev flag takes effect.
    config(['catalog.show_unverified' => true]);

    Product::factory()->unverified()->create(['product_name' => 'Dev Only']);

    $this->getJson('/api/catalog')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product_name', 'Dev Only');
});

it('never shows unverified products in production, even if the dev flag is on', function () {
    $this->app->detectEnvironment(fn () => 'production');
    config(['catalog.show_unverified' => true]);

    Product::factory()->verified()->create(['product_name' => 'Verified']);
    Product::factory()->unverified()->create(['product_name' => 'Unverified']);

    $response = $this->getJson('/api/catalog');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product_name', 'Verified');
});

it('paginates results and returns pagination metadata', function () {
    Product::factory()->count(30)->verified()->create();

    $response = $this->getJson('/api/catalog?per_page=10');

    $response->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta.per_page', 10)
        ->assertJsonPath('meta.total', 30)
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.last_page', 3)
        ->assertJsonStructure(['data', 'links', 'meta']);
});

it('returns a requested later page', function () {
    Product::factory()->count(15)->verified()->create();

    $this->getJson('/api/catalog?per_page=10&page=2')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.current_page', 2);
});

it('filters by category', function () {
    Product::factory()->count(2)->verified()->dresses()->create();
    Product::factory()->count(3)->verified()->upperBody()->create();

    $response = $this->getJson('/api/catalog?category=dresses');

    $response->assertOk()->assertJsonCount(2, 'data');

    foreach ($response->json('data') as $item) {
        expect($item['category'])->toBe('dresses');
    }
});

it('rejects an unknown category with a validation error', function () {
    $this->getJson('/api/catalog?category=shoes')->assertStatus(422);
});

it('rejects an out-of-range page size', function () {
    $this->getJson('/api/catalog?per_page=999')->assertStatus(422);
});

/*
 * Amazon only: the other sources stay in the database but are not shown.
 */

it('shows only Amazon items while CATALOG_AMAZON_ONLY is on, and brings the rest back when off', function () {
    Product::factory()->verified()->create(['product_name' => 'Amazon Skirt', 'raw' => ['ASIN' => 'B0TEST0001']]);
    Product::factory()->verified()->create(['product_name' => 'Curated Dress', 'raw' => ['title' => 'x']]);

    config(['catalog.amazon_only' => true]);
    $this->getJson('/api/catalog')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.product_name', 'Amazon Skirt');

    config(['catalog.amazon_only' => false]);
    $this->getJson('/api/catalog')->assertOk()->assertJsonCount(2, 'data');
});

it('is on by default', function () {
    expect(config('catalog.amazon_only'))->toBeFalse() // phpunit.xml turns it off for the rest of the suite
        ->and(file_get_contents(base_path('config/catalog.php')))->toContain("env('CATALOG_AMAZON_ONLY', true)");
});
