<?php

use App\Models\Product;
use App\Services\Outfit\OutfitRejection;
use App\Services\Outfit\OutfitValidator;

/*
 * Set-composition rules for an outfit (checked before any engine call). Pure
 * decision logic over Product categories — no DB persistence needed (make()), no
 * network. Order is resolved from config('fitting_room.outfit.layer_order').
 */

function outfitValidator(): OutfitValidator
{
    return app(OutfitValidator::class);
}

/** @param array<string> $categories */
function productsOfCategories(array $categories): array
{
    return array_map(fn (string $c) => Product::factory()->make(['category' => $c]), $categories);
}

it('accepts a lower + upper set and orders it base-layer-first regardless of pick order', function () {
    // Picked upper FIRST, lower second — the wearing order must still be lower→upper.
    $plan = outfitValidator()->validate(productsOfCategories(['upper_body', 'lower_body']));

    expect($plan->valid)->toBeTrue()
        ->and($plan->products[0]->category)->toBe('lower_body')
        ->and($plan->products[1]->category)->toBe('upper_body')
        // A set reaching the legs needs a full-height photo.
        ->and($plan->fullBody)->toBeTrue()
        ->and($plan->representativeCategory())->toBe('lower_body');
});

it('rejects a dress in a set (dresses are solo-only)', function () {
    $plan = outfitValidator()->validate(productsOfCategories(['lower_body', 'dresses']));

    expect($plan->valid)->toBeFalse()
        ->and($plan->reason)->toBe(OutfitRejection::DressMustBeSolo);
});

it('rejects two tops (one garment per layer)', function () {
    $plan = outfitValidator()->validate(productsOfCategories(['upper_body', 'upper_body']));

    expect($plan->valid)->toBeFalse()
        ->and($plan->reason)->toBe(OutfitRejection::DuplicateLayer);
});

it('rejects two bottoms (one garment per layer)', function () {
    $plan = outfitValidator()->validate(productsOfCategories(['lower_body', 'lower_body']));

    expect($plan->valid)->toBeFalse()
        ->and($plan->reason)->toBe(OutfitRejection::DuplicateLayer);
});

it('rejects a set larger than the configured cap', function () {
    config(['fitting_room.outfit.max_items' => 2]);

    // Three distinct layers would be valid composition-wise, but exceed the cap.
    $plan = outfitValidator()->validate(productsOfCategories(['lower_body', 'upper_body', 'outer']));

    expect($plan->valid)->toBeFalse()
        ->and($plan->reason)->toBe(OutfitRejection::TooManyItems);
});

it('rejects fewer than two items', function () {
    $plan = outfitValidator()->validate(productsOfCategories(['upper_body']));

    expect($plan->valid)->toBeFalse()
        ->and($plan->reason)->toBe(OutfitRejection::TooFewItems);
});

it('rejects an unknown layer category', function () {
    $plan = outfitValidator()->validate(productsOfCategories(['upper_body', 'hat']));

    expect($plan->valid)->toBeFalse()
        ->and($plan->reason)->toBe(OutfitRejection::UnsupportedCategory);
});

it('is extensible: raising the cap and layer order admits a three-layer set', function () {
    // Architecture under N: adding 'outer' to the wearing order + a higher cap is
    // all it takes — no rule rewrite. (No catalog item uses 'outer' yet.)
    config(['fitting_room.outfit.max_items' => 3]);

    $plan = outfitValidator()->validate(productsOfCategories(['outer', 'upper_body', 'lower_body']));

    expect($plan->valid)->toBeTrue()
        ->and(array_map(fn ($p) => $p->category, $plan->products))
        ->toBe(['lower_body', 'upper_body', 'outer']);
});
