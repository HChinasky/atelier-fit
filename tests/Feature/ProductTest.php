<?php

use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('creates the products table with all the feed columns', function () {
    expect(Schema::hasTable('products'))->toBeTrue();

    expect(Schema::hasColumns('products', [
        'id', 'external_id', 'brand_name', 'product_name', 'price_aed',
        'image_flat_url', 'shop_url', 'category', 'flat_bg_verified', 'raw',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('stores raw as native jsonb', function () {
    $type = DB::scalar(
        "select data_type from information_schema.columns where table_name = 'products' and column_name = 'raw'"
    );

    expect($type)->toBe('jsonb');
});

it('has a unique index on external_id and a partial index on verified products', function () {
    $defs = collect(DB::select("select indexname, indexdef from pg_indexes where tablename = 'products'"))
        ->pluck('indexdef');

    // UNIQUE(external_id)
    expect($defs->contains(fn ($d) => str_contains($d, 'UNIQUE') && str_contains($d, 'external_id')))->toBeTrue();

    // Partial index: ... WHERE (flat_bg_verified = true)
    expect($defs->contains(fn ($d) => str_contains($d, 'flat_bg_verified') && str_contains($d, 'WHERE')))->toBeTrue();
});

it('enforces the unique external_id at the database level', function () {
    Product::factory()->create(['external_id' => 'EXT-DUP']);

    expect(fn () => Product::factory()->create(['external_id' => 'EXT-DUP']))
        ->toThrow(QueryException::class);
});

it('rejects a category outside the allowed set', function () {
    expect(fn () => Product::factory()->create(['category' => 'bags']))
        ->toThrow(QueryException::class);
});

it('accepts shoes and headwear, the capsule-only categories', function () {
    expect(Product::factory()->shoes()->create()->category)->toBe('shoes')
        ->and(Product::factory()->headwear()->create()->category)->toBe('headwear');
});

it('persists a valid product through its factory', function () {
    $product = Product::factory()->create();

    expect($product->exists)->toBeTrue()
        ->and(Product::whereKey($product->getKey())->exists())->toBeTrue()
        ->and(Product::count())->toBe(1);
});

it('casts attributes correctly', function () {
    $product = Product::factory()->create([
        'price_aed' => 199.99,
        'flat_bg_verified' => true,
    ])->fresh();

    // raw round-trips as a (nested) array
    expect($product->raw)->toBeArray()
        ->and($product->raw['price']['currency'])->toBe('AED');

    // price kept exactly, no float drift (decimal:2 cast → string)
    expect($product->price_aed)->toBe('199.99');

    // boolean, not "1"/0
    expect($product->flat_bg_verified)->toBeTrue();
});

it('defaults to unverified and flips with the verified state', function () {
    expect(Product::factory()->create()->flat_bg_verified)->toBeFalse()
        ->and(Product::factory()->verified()->create()->flat_bg_verified)->toBeTrue();
});

it('pins the category via category states', function () {
    expect(Product::factory()->dresses()->create()->category)->toBe('dresses')
        ->and(Product::factory()->upperBody()->create()->category)->toBe('upper_body');
});
