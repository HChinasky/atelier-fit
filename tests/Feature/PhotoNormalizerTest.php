<?php

use App\Services\Photo\InterventionPhotoNormalizer;
use App\Services\Photo\PhotoNormalizationException;
use App\Services\Photo\PhotoNormalizer;
use Illuminate\Support\Facades\Log;

/*
 * Geometry normalization (9.3): EXIF orientation + aspect-preserving downscale +
 * format. Pure local geometry — no ML, no external calls. Images are generated
 * in-memory with GD (or a committed EXIF fixture); nothing hits the network.
 */

function normalizer(): PhotoNormalizer
{
    return app(InterventionPhotoNormalizer::class);
}

function jpegBytes(int $width, int $height): string
{
    $im = imagecreatetruecolor($width, $height);
    imagefill($im, 0, 0, imagecolorallocate($im, 120, 160, 200));
    ob_start();
    imagejpeg($im, null, 92);
    $bytes = ob_get_clean();
    imagedestroy($im);

    return $bytes;
}

function pngBytes(int $width, int $height): string
{
    $im = imagecreatetruecolor($width, $height);
    imagefill($im, 0, 0, imagecolorallocate($im, 30, 90, 150));
    ob_start();
    imagepng($im);
    $bytes = ob_get_clean();
    imagedestroy($im);

    return $bytes;
}

it('applies EXIF orientation: pixels rotated, dimensions swapped, flag reset', function () {
    // Fixture is stored 120x60 with EXIF orientation 6 (rotate 90° CW for display).
    $bytes = file_get_contents(base_path('tests/Fixtures/exif_orientation_6.jpg'));

    $result = normalizer()->normalize($bytes);

    // After orientation the visible image is portrait: 60 wide x 120 tall.
    expect($result->width)->toBe(60)
        ->and($result->height)->toBe(120)
        ->and($result->format)->toBe('jpeg');

    // The reported size matches the actual encoded pixels.
    [$w, $h] = getimagesizefromstring($result->contents);
    expect($w)->toBe(60)->and($h)->toBe(120);

    // Flag reset / no double rotation: normalizing the OUTPUT again must NOT
    // rotate it a second time (it would become 120x60 if the flag survived).
    $again = normalizer()->normalize($result->contents);
    expect($again->width)->toBe(60)->and($again->height)->toBe(120);
});

it('downscales a large photo to the max dimension, preserving aspect ratio', function () {
    config(['photo.normalization.max_dimension' => 2000]);

    $result = normalizer()->normalize(jpegBytes(4000, 3000));

    // Longest side capped at 2000; ratio 4:3 preserved (no stretch).
    expect($result->width)->toBe(2000)
        ->and($result->height)->toBe(1500);

    expect($result->width / $result->height)->toBe(4000 / 3000);
});

it('leaves a smaller photo untouched (never upscales)', function () {
    config(['photo.normalization.max_dimension' => 2000]);

    $result = normalizer()->normalize(jpegBytes(800, 600));

    expect($result->width)->toBe(800)
        ->and($result->height)->toBe(600);
});

it('converts to the configured target format (png -> jpeg by default)', function () {
    $result = normalizer()->normalize(pngBytes(640, 480));

    expect($result->format)->toBe('jpeg');
    expect(getimagesizefromstring($result->contents)[2])->toBe(IMAGETYPE_JPEG);
});

it('honours a configured target format (jpeg -> png)', function () {
    config(['photo.normalization.format' => 'png']);

    $result = normalizer()->normalize(jpegBytes(320, 240));

    expect($result->format)->toBe('png');
    expect(getimagesizefromstring($result->contents)[2])->toBe(IMAGETYPE_PNG);
});

it('returns correct metadata matching the encoded bytes', function () {
    $result = normalizer()->normalize(jpegBytes(1000, 750));

    [$w, $h, $type] = getimagesizefromstring($result->contents);
    expect($result->width)->toBe($w)
        ->and($result->height)->toBe($h)
        ->and($type)->toBe(IMAGETYPE_JPEG);
});

it('throws PhotoNormalizationException on undecodable input', function () {
    expect(fn () => normalizer()->normalize('this is not an image'))
        ->toThrow(PhotoNormalizationException::class);
});

it('does not log image content during normalization', function () {
    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = $message->message.' '.json_encode($message->context);
    });

    normalizer()->normalize(jpegBytes(500, 500));

    // Normalizer is silent on success; nothing about the photo is logged.
    expect($logged)->toBeEmpty();
});
