<?php

use App\Services\Color\DeltaE;

/*
 * CIEDE2000, pinned to the published test data of Sharma, Wu and Dalal
 * (2005), Table 1 — the pairs chosen to exercise every branch of the formula:
 * the hue wrap-around, zero chroma, the blue-region rotation term.
 */

it('matches the published CIEDE2000 test pairs', function (array $first, array $second, float $expected) {
    expect(DeltaE::ciede2000($first, $second))->toEqualWithDelta($expected, 0.0001);
})->with([
    'pair 1' => [[50.0000, 2.6772, -79.7751], [50.0000, 0.0000, -82.7485], 2.0425],
    'pair 2' => [[50.0000, 3.1571, -77.2803], [50.0000, 0.0000, -82.7485], 2.8615],
    'pair 3' => [[50.0000, 2.8361, -74.0200], [50.0000, 0.0000, -82.7485], 3.4412],
    'pair 4' => [[50.0000, -1.3802, -84.2814], [50.0000, 0.0000, -82.7485], 1.0000],
    'pair 7' => [[50.0000, 0.0000, 0.0000], [50.0000, -1.0000, 2.0000], 2.3669],
    'pair 11 (hue wrap)' => [[50.0000, 2.4900, -0.0010], [50.0000, -2.4900, 0.0009], 7.1792],
    'pair 25' => [[60.2574, -34.0099, 36.2677], [60.4626, -34.1751, 39.4387], 1.2644],
    'pair 27 (blue rotation)' => [[22.7233, 20.0904, -46.6940], [23.0331, 14.9730, -42.5619], 2.0373],
    'pair 31' => [[90.9257, -0.5406, -0.9208], [88.6381, -0.8985, -0.7239], 1.5381],
    'pair 34 (near black)' => [[2.0776, 0.0795, -1.1350], [0.9033, -0.0636, -0.5514], 0.9082],
]);

it('is zero for a colour against itself and symmetric', function () {
    $navy = [25.0, 5.0, -30.0];
    $mustard = [70.0, 5.0, 60.0];

    expect(DeltaE::ciede2000($navy, $navy))->toBe(0.0)
        ->and(DeltaE::ciede2000($navy, $mustard))->toEqualWithDelta(DeltaE::ciede2000($mustard, $navy), 1e-9);
});
