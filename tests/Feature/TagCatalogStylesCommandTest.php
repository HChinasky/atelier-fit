<?php

use App\Models\Product;

it('tags every product it may, and keeps the ones a stronger source set', function () {
    $evening = Product::factory()->create(['product_name' => 'Cocktail dress']);
    $plain = Product::factory()->create(['product_name' => 'Linen shirt']);
    $manual = Product::factory()->forOccasion('office', 'business', 'manual')->create(['product_name' => 'Gym top']);

    $this->artisan('catalog:tag-styles')
        ->expectsOutputToContain('2 product(s) tagged, 1 kept')
        ->assertSuccessful();

    expect($evening->refresh()->occasion)->toBe('evening')
        ->and($plain->refresh()->occasion)->toBe('universal')
        ->and($plain->style_source)->toBe('parse')
        ->and($manual->refresh()->occasion)->toBe('office')
        ->and($manual->style_source)->toBe('manual');
});

it('is idempotent: a second run writes nothing', function () {
    Product::factory()->count(3)->create(['product_name' => 'Evening gown']);

    $this->artisan('catalog:tag-styles')->assertSuccessful();
    $stamps = Product::pluck('style_tagged_at', 'id')->map->toIso8601String();

    $this->artisan('catalog:tag-styles')
        ->expectsOutputToContain('0 product(s) tagged')
        ->assertSuccessful();

    expect(Product::pluck('style_tagged_at', 'id')->map->toIso8601String())->toEqual($stamps);
});

it('retags a parsed product whose words changed', function () {
    $product = Product::factory()->create(['product_name' => 'Evening gown']);
    $this->artisan('catalog:tag-styles');

    $product->update(['product_name' => 'Office blazer']);
    $this->artisan('catalog:tag-styles');

    expect($product->refresh()->occasion)->toBe('office');
});

it('tags products as the feed imports them, honestly', function () {
    $this->artisan('catalog:import-file', ['--all-brands' => true, '--force' => true])->assertSuccessful();

    // Every imported product carries a parsed tag; the ones no word decides
    // are universal rather than guessed.
    expect(Product::count())->toBeGreaterThan(0)
        ->and(Product::whereNull('style_source')->count())->toBe(0)
        ->and(Product::where('occasion', 'universal')->count())->toBeGreaterThan(0);
});
