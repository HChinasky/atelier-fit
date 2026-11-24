<?php

use App\Services\Photo\CropBox;
use App\Services\Photo\InterventionPhotoAspectFitter;
use Tests\Support\PoseFixtures;

/*
 * Output aspect fit (9.6): widen/heighten the cropped photo to the engine's output
 * ratio using the photo's REAL pixels, padding neutral only where the photo runs
 * out — so the Before/After slider shares one frame with no letterbox bars and the
 * person is never cropped. Pure in-memory geometry; no external calls.
 */

function fitter(): InterventionPhotoAspectFitter
{
    return app(InterventionPhotoAspectFitter::class);
}

it('is disabled when no target aspect is configured', function () {
    config(['photo.output.aspect' => '']);

    expect(fitter()->enabled())->toBeFalse();

    $bytes = PoseFixtures::jpeg(800, 1200);
    expect(fitter()->fit($bytes, null, 'jpeg'))->toBe($bytes); // unchanged
});

it('widens a narrow crop to the target aspect using real pixels (no pad needed)', function () {
    config(['photo.output.aspect' => '3:4']);

    // 1000x1500 photo; a narrow central person region. Expanding to 3:4 stays inside
    // the photo, so the result is exactly 3:4 from real pixels (no padding).
    $box = new CropBox(x: 0.40, y: 0.10, width: 0.20, height: 0.80, aspect: 0.25);

    $out = fitter()->fit(PoseFixtures::jpeg(1000, 1500), $box, 'jpeg');

    [$w, $h] = getimagesizefromstring($out);
    expect($w / $h)->toBeGreaterThan(0.74)->and($w / $h)->toBeLessThan(0.76); // ~3:4
    // Widened well beyond the ~200px person column, but within the 1000px photo.
    expect($w)->toBeGreaterThan(700)->and($w)->toBeLessThanOrEqual(1000);
});

it('pads with the neutral colour only where the photo runs out', function () {
    config(['photo.output.aspect' => '3:4', 'photo.output.pad_color' => '#f0f0f0']);

    // The box is the whole 1000x1500 (2:3) photo: reaching 3:4 needs more WIDTH than
    // the photo has, so the sides must be padded with the neutral colour.
    $box = new CropBox(x: 0.0, y: 0.0, width: 1.0, height: 1.0, aspect: 1000 / 1500);

    $out = fitter()->fit(PoseFixtures::jpeg(1000, 1500), $box, 'jpeg');

    [$w, $h] = getimagesizefromstring($out);
    expect($w / $h)->toBeGreaterThan(0.74)->and($w / $h)->toBeLessThan(0.76)
        ->and($w)->toBeGreaterThan(1000); // wider than the source → padded sides

    // The far-left column is the neutral pad, not the photo's blue fill (130,170,210).
    $img = imagecreatefromstring($out);
    $rgb = imagecolorat($img, 1, (int) ($h / 2));
    imagedestroy($img);
    $r = ($rgb >> 16) & 0xFF;
    expect($r)->toBeGreaterThan(200); // light neutral pad, not the blue photo
});

it('fits the whole photo when there is no crop box', function () {
    config(['photo.output.aspect' => '1:1']);

    $out = fitter()->fit(PoseFixtures::jpeg(1000, 1500), null, 'jpeg');

    [$w, $h] = getimagesizefromstring($out);
    expect($w)->toBe($h); // square
});
