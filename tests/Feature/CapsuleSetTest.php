<?php

use App\Models\Product;
use App\Services\Capsules\Capsule;
use App\Services\Capsules\CapsuleSet;

/*
 * A capsule page lists the pieces its looks use — each once, tops first —
 * and the colours of those pieces as its swatches.
 */

function setPiece(int $id, string $category, ?string $hex = '#1B2A4A'): Product
{
    $product = Product::factory()->ofCategory($category)->measured()->make(['id' => $id]);
    if ($hex !== null) {
        $colour = $product->color;
        $colour['colours'][0]['hex'] = $hex;
        $product->color = $colour;
    } else {
        $product->color_status = 'rejected';
    }

    return $product;
}

function setLook(array $pieces): Capsule
{
    return new Capsule('office', Capsule::KIND_SEPARATES, $pieces, 0.8, 0, 'Why it suits.');
}

it('lists every piece once, in slot order', function () {
    $top = setPiece(1, 'upper_body');
    $trousers = setPiece(2, 'lower_body');
    $skirt = setPiece(3, 'lower_body');
    $shoes = setPiece(4, 'shoes');

    $set = new CapsuleSet('office', [
        setLook(['shoes' => $shoes, 'lower_body' => $trousers, 'upper_body' => $top]),
        setLook(['upper_body' => $top, 'lower_body' => $skirt, 'shoes' => $shoes]),
    ]);

    expect(array_map(fn (Product $p) => $p->id, $set->pieces()))->toBe([1, 2, 3, 4]);
});

it('takes the swatches from the pieces, each colour once, four at most', function () {
    $set = new CapsuleSet('office', [
        setLook(['upper_body' => setPiece(1, 'upper_body', '#111111'), 'lower_body' => setPiece(2, 'lower_body', '#222222'), 'shoes' => setPiece(3, 'shoes', '#111111')]),
        setLook(['upper_body' => setPiece(4, 'upper_body', '#333333'), 'lower_body' => setPiece(5, 'lower_body', null), 'shoes' => setPiece(6, 'shoes', '#444444')]),
        setLook(['dresses' => setPiece(7, 'dresses', '#555555'), 'shoes' => setPiece(8, 'shoes', '#666666')]),
    ]);

    // Pieces run tops, bottoms, dresses, shoes: 1, 4, 2, 5 (unmeasured), 7, …
    expect($set->colours())->toBe(['#111111', '#333333', '#222222', '#555555'])
        ->and($set->colours(10))->toHaveCount(6);
});

it('has neither pieces nor colours when no look could be made', function () {
    $set = new CapsuleSet('evening', [], CapsuleSet::NO_OCCASION_PIECES);

    expect($set->pieces())->toBe([])->and($set->colours())->toBe([]);
});
