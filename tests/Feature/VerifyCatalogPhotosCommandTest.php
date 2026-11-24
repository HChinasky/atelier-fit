<?php

use App\Models\Product;

/*
 * catalog:verify is the human's voice on flat_bg_verified — the flag that
 * decides whether a product reaches GET /api/catalog. These tests pin the
 * behaviour that matters operationally: it flips exactly the products named,
 * it can take the flag back, and it refuses ambiguous invocations instead of
 * guessing (a wrong guess would either hide the catalog or publish photos the
 * try-on engine cannot use).
 */

it('marks the named products try-on ready and leaves the rest untouched', function () {
    $abaya = Product::factory()->unverified()->create(['external_id' => 'BOUG-ABAYA-12']);
    $hijab = Product::factory()->unverified()->create(['external_id' => 'MODA-HIJAB-14']);
    $other = Product::factory()->unverified()->create(['external_id' => 'ZARA-SHIRT-01']);

    $this->artisan('catalog:verify', ['external_id' => ['BOUG-ABAYA-12', 'MODA-HIJAB-14']])
        ->assertSuccessful();

    expect($abaya->refresh()->flat_bg_verified)->toBeTrue()
        ->and($hijab->refresh()->flat_bg_verified)->toBeTrue()
        ->and($other->refresh()->flat_bg_verified)->toBeFalse();
});

it('warns about an unknown external id without failing the run', function () {
    Product::factory()->unverified()->create(['external_id' => 'MODA-SHAYLA-15']);

    $this->artisan('catalog:verify', ['external_id' => ['MODA-SHAYLA-15', 'NO-SUCH-ID']])
        ->expectsOutputToContain('Unknown external_id, skipped: NO-SUCH-ID')
        ->assertSuccessful();

    expect(Product::where('external_id', 'MODA-SHAYLA-15')->value('flat_bg_verified'))->toBeTrue();
});

it('revokes verification, taking a product back out of the catalog', function () {
    $product = Product::factory()->verified()->create(['external_id' => 'MODA-NIQAB-16']);

    $this->artisan('catalog:verify', ['external_id' => ['MODA-NIQAB-16'], '--revoke' => true])
        ->assertSuccessful();

    expect($product->refresh()->flat_bg_verified)->toBeFalse();
});

it('verifies the whole catalog with --all', function () {
    Product::factory()->unverified()->count(3)->create();

    $this->artisan('catalog:verify', ['--all' => true])->assertSuccessful();

    expect(Product::where('flat_bg_verified', true)->count())->toBe(3);
});

it('refuses to run with neither ids nor --all', function () {
    $product = Product::factory()->unverified()->create();

    $this->artisan('catalog:verify')->assertFailed();

    expect($product->refresh()->flat_bg_verified)->toBeFalse();
});

it('refuses to run with both ids and --all, rather than guessing which one wins', function () {
    $named = Product::factory()->unverified()->create(['external_id' => 'MODA-KHIMAR-13']);
    $other = Product::factory()->unverified()->create();

    $this->artisan('catalog:verify', ['external_id' => ['MODA-KHIMAR-13'], '--all' => true])
        ->assertFailed();

    expect($named->refresh()->flat_bg_verified)->toBeFalse()
        ->and($other->refresh()->flat_bg_verified)->toBeFalse();
});
