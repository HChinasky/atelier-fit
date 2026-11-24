<?php

use App\Models\Product;
use App\Models\ProductSeasonMatch;
use App\Models\Season;
use App\Models\SeasonColor;
use App\Services\Color\GarmentPaletteMatcher;

/*
 * Does a measured garment suit a season? Judged against the season's own
 * swatches: near a colour to wear and nearer that than a colour to avoid,
 * with no big patch of the garment on the avoid side.
 */

const NAVY = [25.0, 5.0, -30.0];
const RUST = [45.0, 35.0, 45.0];

beforeEach(function () {
    config(['color_analysis.garment_match' => ['max_delta_e' => 15.0, 'clash_share' => 0.25, 'min_share' => 0.15]]);
});

/** A season that wears navy and avoids rust. */
function navySeason(string $slug = 'deep_winter'): Season
{
    $season = Season::factory()->slug($slug)->create();
    SeasonColor::factory()->for($season)->group(SeasonColor::GROUP_NEUTRALS)
        ->create(['lab_l' => NAVY[0], 'lab_a' => NAVY[1], 'lab_b' => NAVY[2]]);
    SeasonColor::factory()->for($season)->group(SeasonColor::GROUP_AVOID)
        ->create(['lab_l' => RUST[0], 'lab_a' => RUST[1], 'lab_b' => RUST[2]]);

    return $season->load('colors');
}

function judged(array $colours, Season $season): array
{
    $shaped = array_map(fn (array $c) => ['L' => $c[0], 'a' => $c[1], 'b' => $c[2], 'share' => $c[3]], $colours);

    return app(GarmentPaletteMatcher::class)->judge($shaped, $season->colors);
}

it('calls a garment on one of the colours to wear a perfect match', function () {
    expect(judged([[...NAVY, 1.0]], navySeason()))->toBe([
        'suits' => true,
        'score' => 1.0,
        'delta_e' => 0.0,
        'palette_group' => SeasonColor::GROUP_NEUTRALS,
    ]);
});

it('lets a nearby shade suit, scoring it lower the further it sits', function () {
    $near = judged([[28.0, 6.0, -26.0, 1.0]], navySeason());

    expect($near['suits'])->toBeTrue()
        ->and($near['score'])->toBeGreaterThan(0.0)->toBeLessThan(1.0);
});

it('does not suit a garment on the colours to avoid', function () {
    $result = judged([[...RUST, 1.0]], navySeason());

    expect($result['suits'])->toBeFalse()
        ->and($result['score'])->toBe(0.0)
        ->and($result['palette_group'])->toBeNull();
});

it('does not suit a colour too far from anything the season wears', function () {
    $result = judged([[90.0, -40.0, 60.0, 1.0]], navySeason());

    expect($result['suits'])->toBeFalse()
        ->and($result['delta_e'])->toBeGreaterThan(15.0);
});

it('is spoiled by a big patch on the avoid side, but not by a small one', function () {
    $season = navySeason();

    expect(judged([[...NAVY, 0.7], [...RUST, 0.3]], $season)['suits'])->toBeFalse()
        ->and(judged([[...NAVY, 0.9], [...RUST, 0.1]], $season)['suits'])->toBeTrue();
});

it('writes a row per season, and rewrites them on the next match', function () {
    navySeason('deep_winter');
    navySeason('true_winter');
    $product = Product::factory()->verified()->measured([[...NAVY, 1.0]])->create();
    $matcher = app(GarmentPaletteMatcher::class);

    $matcher->match($product);
    $matcher->match($product);

    expect(ProductSeasonMatch::where('product_id', $product->id)->pluck('season')->sort()->values()->all())
        ->toBe(['deep_winter', 'true_winter'])
        ->and(ProductSeasonMatch::where('product_id', $product->id)->where('suits', true)->count())->toBe(2);
});

it('leaves no rows for a product without a measured colour', function () {
    navySeason();
    $product = Product::factory()->verified()->colourRejected()->create();
    ProductSeasonMatch::factory()->for($product)->forSeason('deep_winter')->create();

    app(GarmentPaletteMatcher::class)->match($product);

    expect(ProductSeasonMatch::where('product_id', $product->id)->count())->toBe(0);
});
