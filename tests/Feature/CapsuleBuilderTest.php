<?php

use App\Models\Product;
use App\Models\ProductSeasonMatch;
use App\Services\Capsules\Capsule;
use App\Services\Capsules\CapsuleBuilder;
use App\Services\Capsules\CapsuleSet;

/*
 * CapsuleBuilder: whole looks in the member's palette, per occasion. Colour
 * matches are written straight as ProductSeasonMatch rows (the matcher has its
 * own tests); a piece's main colour is set through the measured() state so the
 * "in tone" rule has a colour to measure.
 */

const CAPSULE_SEASON = 'deep_winter';

const CAPSULE_NAVY = [25.0, 5.0, -30.0];
const CAPSULE_RED = [45.0, 62.0, 40.0];
const CAPSULE_NEAR_RED = [47.0, 58.0, 36.0];
const CAPSULE_GREEN = [55.0, -45.0, 35.0];

/**
 * A published piece that suits CAPSULE_SEASON with the given palette group and score.
 *
 * @param  array{0: float, 1: float, 2: float}  $lab
 */
function capsulePiece(string $category, string $group = 'neutrals', float $score = 0.8, string $occasion = 'universal', array $lab = CAPSULE_NAVY, string $season = CAPSULE_SEASON): Product
{
    $factory = Product::factory()->verified()->ofCategory($category)->measured([[...$lab, 1.0]]);
    if ($occasion !== 'universal') {
        $factory = $factory->forOccasion($occasion);
    }

    $product = $factory->create();

    ProductSeasonMatch::factory()->for($product)->forSeason($season)
        ->state(['palette_group' => $group, 'score' => $score, 'suits' => true])
        ->create();

    return $product;
}

function buildCapsules(string $occasion = 'casual'): CapsuleSet
{
    return app(CapsuleBuilder::class)->build(CAPSULE_SEASON, $occasion);
}

/** @return list<string> */
function capsuleSlots(Capsule $capsule): array
{
    return array_keys($capsule->pieces);
}

it('builds top + bottom + shoes from the member palette for the occasion', function () {
    foreach (range(1, 3) as $i) {
        capsulePiece('upper_body');
        capsulePiece('lower_body');
        capsulePiece('shoes');
    }

    $set = buildCapsules('casual');

    expect($set->reason)->toBeNull()
        ->and($set->capsules)->toHaveCount(3);

    foreach ($set->capsules as $capsule) {
        expect(capsuleSlots($capsule))->toBe(['upper_body', 'lower_body', 'shoes'])
            ->and($capsule->kind)->toBe(Capsule::KIND_SEPARATES)
            ->and($capsule->occasion)->toBe('casual');
    }
});

it('takes only pieces that suit the season, are published and fit the occasion', function () {
    $top = capsulePiece('upper_body', occasion: 'office');
    $bottom = capsulePiece('lower_body');                       // universal: goes anywhere
    $shoes = capsulePiece('shoes');

    capsulePiece('upper_body', occasion: 'evening');            // another occasion
    capsulePiece('upper_body', season: 'light_spring');         // another season's palette
    $clashing = Product::factory()->verified()->upperBody()->measured()->create();
    ProductSeasonMatch::factory()->for($clashing)->forSeason(CAPSULE_SEASON)->clashing()->create();
    $draft = Product::factory()->unverified()->upperBody()->measured()->create();
    ProductSeasonMatch::factory()->for($draft)->forSeason(CAPSULE_SEASON)->neutral()->create();

    $ids = collect(buildCapsules('office')->capsules)->flatMap->productIds()->unique()->sort()->values()->all();

    expect($ids)->toBe(collect([$top->id, $bottom->id, $shoes->id])->sort()->values()->all());
});

it('keeps one occasion per look: an evening piece never joins an office look', function () {
    capsulePiece('upper_body', occasion: 'evening');
    capsulePiece('lower_body', occasion: 'office');
    capsulePiece('shoes');

    $set = buildCapsules('office');

    expect($set->isEmpty())->toBeTrue()
        ->and($set->missingSlots)->toBe(['upper_body', 'dresses']);
});

it('builds a dress capsule as dress + shoes, never with a top or bottom', function () {
    capsulePiece('dresses', occasion: 'evening');
    capsulePiece('dresses');
    capsulePiece('shoes');

    $set = buildCapsules('evening');

    expect($set->capsules)->not->toBeEmpty();
    foreach ($set->capsules as $capsule) {
        expect(capsuleSlots($capsule))->toBe(['dresses', 'shoes'])
            ->and($capsule->kind)->toBe(Capsule::KIND_DRESS);
    }
});

it('never mixes a dress with separates when both are in the catalog', function () {
    foreach (range(1, 3) as $i) {
        capsulePiece('upper_body');
        capsulePiece('lower_body');
        capsulePiece('dresses');
        capsulePiece('shoes');
    }

    $set = buildCapsules('casual');
    $kinds = collect($set->capsules)->pluck('kind')->unique();

    expect($kinds)->toContain(Capsule::KIND_DRESS, Capsule::KIND_SEPARATES);
    foreach ($set->capsules as $capsule) {
        $slots = capsuleSlots($capsule);
        $hasDress = in_array('dresses', $slots, true);
        $hasSeparates = array_intersect(['upper_body', 'lower_body'], $slots) !== [];

        expect($hasDress xor $hasSeparates)->toBeTrue();
    }
});

it('puts no more than two accents in a look, and always a neutral', function () {
    foreach (range(1, 3) as $i) {
        capsulePiece('upper_body', 'accents', 0.95, lab: CAPSULE_RED);
        capsulePiece('lower_body', 'accents', 0.95, lab: CAPSULE_RED);
        capsulePiece('shoes', 'accents', 0.95, lab: CAPSULE_NEAR_RED);
    }
    capsulePiece('lower_body', 'neutrals', 0.5);
    capsulePiece('shoes', 'neutrals', 0.5);

    $set = buildCapsules('casual');

    expect($set->capsules)->not->toBeEmpty();
    foreach ($set->capsules as $capsule) {
        expect($capsule->accents)->toBeLessThanOrEqual(2)
            ->and($capsule->accents)->toBeLessThan(count($capsule->pieces));
    }
});

it('does not build a look of accents only, and says why', function () {
    capsulePiece('upper_body', 'accents', lab: CAPSULE_RED);
    capsulePiece('lower_body', 'accents', lab: CAPSULE_RED);
    capsulePiece('shoes', 'accents', lab: CAPSULE_NEAR_RED);

    $set = buildCapsules('casual');

    expect($set->isEmpty())->toBeTrue()
        ->and($set->reason)->toBe(CapsuleSet::NO_BALANCED_LOOK);
});

it('wears accent shoes only in tone with the look', function () {
    capsulePiece('upper_body', 'accents', lab: CAPSULE_RED);
    capsulePiece('lower_body', 'neutrals');
    $inTone = capsulePiece('shoes', 'accents', 0.6, lab: CAPSULE_NEAR_RED);
    $offTone = capsulePiece('shoes', 'accents', 0.99, lab: CAPSULE_GREEN);

    $shoes = collect(buildCapsules('casual')->capsules)->map(fn (Capsule $c) => $c->pieces['shoes']->id)->unique()->values();

    expect($shoes->all())->toBe([$inTone->id])
        ->and($shoes)->not->toContain($offTone->id);
});

it('adds a hat when one fits and builds the look without one otherwise', function () {
    capsulePiece('upper_body');
    capsulePiece('lower_body');
    capsulePiece('shoes');

    expect(capsuleSlots(buildCapsules('casual')->capsules[0]))->not->toContain('headwear');

    $hat = capsulePiece('headwear');

    $capsule = buildCapsules('casual')->capsules[0];
    expect(capsuleSlots($capsule))->toBe(['upper_body', 'lower_body', 'shoes', 'headwear'])
        ->and($capsule->pieces['headwear']->id)->toBe($hat->id);
});

it('wears each hat once per occasion', function () {
    foreach (range(1, 3) as $i) {
        capsulePiece('upper_body');
        capsulePiece('lower_body');
        capsulePiece('shoes');
    }
    $hat = capsulePiece('headwear');

    $hats = collect(buildCapsules('casual')->capsules)
        ->filter(fn (Capsule $c) => isset($c->pieces['headwear']));

    expect($hats)->toHaveCount(1)
        ->and($hats->first()->pieces['headwear']->id)->toBe($hat->id);
});

it('orders the looks by the score it shows, an occasion piece notwithstanding', function () {
    capsulePiece('upper_body', score: 0.95);
    capsulePiece('upper_body', score: 0.6, occasion: 'office');
    capsulePiece('lower_body', score: 0.9);
    capsulePiece('lower_body', score: 0.5);
    capsulePiece('shoes', score: 0.9);

    $scores = collect(buildCapsules('office')->capsules)->pluck('score');

    expect($scores->all())->toBe($scores->sortDesc()->values()->all());
});

it('puts a piece made for the office or an evening in every such look', function (string $occasion) {
    foreach (range(1, 3) as $i) {
        capsulePiece('upper_body', score: 0.95);
        capsulePiece('lower_body', score: 0.95);
        capsulePiece('shoes', score: 0.95);
    }
    $made = capsulePiece('upper_body', score: 0.4, occasion: $occasion);

    $set = buildCapsules($occasion);

    // Base pieces score higher, but every look is built around the one piece
    // made for the occasion; the tabs no longer repeat each other.
    expect($set->capsules)->not->toBeEmpty();
    foreach ($set->capsules as $capsule) {
        expect($capsule->productIds())->toContain($made->id);
    }
})->with(['office', 'evening']);

it('builds no office look from base pieces alone, and says why', function () {
    capsulePiece('upper_body');
    capsulePiece('lower_body');
    capsulePiece('shoes');
    capsulePiece('headwear', occasion: 'office');   // a hat alone does not make an office look

    $set = buildCapsules('office');

    expect($set->isEmpty())->toBeTrue()
        ->and($set->reason)->toBe(CapsuleSet::NO_OCCASION_PIECES);
});

it('counts shoes made for the occasion', function () {
    capsulePiece('dresses');
    $heels = capsulePiece('shoes', occasion: 'evening');

    $set = buildCapsules('evening');

    expect($set->capsules)->toHaveCount(1)
        ->and($set->capsules[0]->pieces['shoes']->id)->toBe($heels->id);
});

it('prefers shoes made for the occasion over better-matching universal ones', function (string $occasion) {
    capsulePiece('dresses', occasion: $occasion);
    capsulePiece('upper_body', occasion: $occasion);
    capsulePiece('lower_body');
    capsulePiece('shoes', score: 0.99);                          // universal, closer to the palette
    $own = capsulePiece('shoes', score: 0.5, occasion: $occasion);

    $shoes = collect(buildCapsules($occasion)->capsules)->map(fn (Capsule $c) => $c->pieces['shoes']->id)->unique()->values();

    expect($shoes->all())->toBe([$own->id]);
})->with(['office', 'evening', 'casual']);

it('falls back to universal shoes where no occasion pair fits the look', function () {
    capsulePiece('dresses', occasion: 'evening');
    capsulePiece('shoes', 'accents', occasion: 'evening', lab: CAPSULE_GREEN);  // off tone with the navy dress
    $base = capsulePiece('shoes');

    $set = buildCapsules('evening');

    expect($set->capsules)->toHaveCount(1)
        ->and($set->capsules[0]->pieces['shoes']->id)->toBe($base->id);
});

it('still builds casual looks from base pieces alone', function () {
    capsulePiece('upper_body');
    capsulePiece('lower_body');
    capsulePiece('shoes');

    expect(buildCapsules('casual')->capsules)->toHaveCount(1);
});

it('skips a hat that would break the colour balance', function () {
    capsulePiece('upper_body', 'accents', lab: CAPSULE_RED);
    capsulePiece('lower_body', 'accents', lab: CAPSULE_RED);
    capsulePiece('shoes');
    capsulePiece('headwear', 'accents', lab: CAPSULE_NEAR_RED);       // a third accent

    expect(capsuleSlots(buildCapsules('casual')->capsules[0]))->toBe(['upper_body', 'lower_body', 'shoes']);
});

it('does not build a look without shoes, and names the missing slot', function () {
    capsulePiece('upper_body');
    capsulePiece('lower_body');
    capsulePiece('dresses');

    $set = buildCapsules('casual');

    expect($set->isEmpty())->toBeTrue()
        ->and($set->reason)->toBe(CapsuleSet::NO_PIECES)
        ->and($set->missingSlots)->toBe(['shoes']);
});

it('names the garment slots when neither a dress nor a top and bottom are there', function () {
    capsulePiece('upper_body');
    capsulePiece('shoes');

    expect(buildCapsules('casual')->missingSlots)->toBe(['lower_body', 'dresses']);
});

it('offers at most five looks, with no garment repeated while the catalog allows', function () {
    foreach (range(1, 6) as $i) {
        capsulePiece('upper_body', score: 0.5 + $i / 20);
        capsulePiece('lower_body', score: 0.5 + $i / 20);
        capsulePiece('shoes', score: 0.5 + $i / 20);
    }

    $set = buildCapsules('casual');
    $garments = collect($set->capsules)->flatMap(fn (Capsule $c) => [$c->pieces['upper_body']->id, $c->pieces['lower_body']->id]);

    expect($set->capsules)->toHaveCount(5)
        ->and($garments->duplicates())->toBeEmpty();
});

it('fills up to five looks with different outfits when only a pair or two of shoes fit', function () {
    foreach (range(1, 6) as $i) {
        capsulePiece('upper_body');
        capsulePiece('lower_body');
    }
    capsulePiece('shoes', occasion: 'casual');

    $set = buildCapsules('casual');
    $garments = collect($set->capsules)->flatMap(fn (Capsule $c) => [$c->pieces['upper_body']->id, $c->pieces['lower_body']->id]);

    expect($set->capsules)->toHaveCount(5)
        ->and($garments->duplicates())->toBeEmpty();
});

it('still offers three different looks from a small catalog', function () {
    capsulePiece('upper_body');
    capsulePiece('upper_body');
    capsulePiece('lower_body');
    capsulePiece('lower_body');
    capsulePiece('shoes');

    $set = buildCapsules('casual');
    $combos = collect($set->capsules)->map(fn (Capsule $c) => $c->pieces['upper_body']->id.'-'.$c->pieces['lower_body']->id);

    expect(count($set->capsules))->toBeGreaterThanOrEqual(3)->toBeLessThanOrEqual(5)
        ->and($combos->duplicates())->toBeEmpty();
});

it('scores a look by how close its pieces sit to the palette', function () {
    capsulePiece('upper_body', score: 0.9);
    capsulePiece('lower_body', score: 0.6);
    capsulePiece('shoes', score: 0.6);

    $capsule = buildCapsules('casual')->capsules[0];

    expect($capsule->score)->toBe(0.7)
        ->and($capsule->percent())->toBe(70);
});

it('ranks the best colour match first', function () {
    capsulePiece('upper_body', score: 0.5);
    capsulePiece('upper_body', score: 0.95);
    capsulePiece('lower_body', score: 0.9);
    capsulePiece('lower_body', score: 0.4);
    capsulePiece('shoes', score: 0.9);

    $scores = collect(buildCapsules('casual')->capsules)->pluck('score');

    expect($scores->all())->toBe($scores->sortDesc()->values()->all());
});

it('explains a look in words that are true of it', function () {
    capsulePiece('upper_body', 'accents', lab: CAPSULE_RED);
    capsulePiece('lower_body', 'neutrals');
    capsulePiece('shoes', 'neutrals');

    $capsule = buildCapsules('casual')->capsules[0];

    expect($capsule->explanation)
        ->toContain('Глибока зима')
        ->toContain('на щодень')
        ->toContain('один акцент — верх');
});
