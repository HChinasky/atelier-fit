<?php

/*
 * The aspect fitter pads a photo of another shape to 3:4 with the neutral
 * pad colour; the try-on keeps those margins. PadTrimmer takes them back off
 * a finished look — and only them.
 */

use App\Models\Generation;
use App\Services\Photo\PadTrimmer;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

beforeEach(function () {
    config(['photo.output.aspect' => '3:4', 'photo.output.pad_color' => '#f0f0f0', 'photo.normalization.driver' => 'gd']);
});

/** A 300x400 look: a $width-wide dark photo centred on the pad colour. */
function paddedLook(int $width, string $pad = '#f0f0f0'): string
{
    $manager = new ImageManager(new Driver);
    $canvas = $manager->create(300, 400)->fill($pad);
    $canvas->place($manager->create($width, 400)->fill('#3a2e28'), 'top-left', intdiv(300 - $width, 2), 0);

    return (string) $canvas->toPng();
}

function lookSize(string $bytes): array
{
    $image = (new ImageManager(new Driver))->read($bytes);

    return [$image->width(), $image->height()];
}

it('trims the grey margins from both sides of a look', function () {
    expect(lookSize(app(PadTrimmer::class)->trim(paddedLook(180))))->toBe([180, 400]);
});

it('tolerates the slight noise a generated margin carries', function () {
    expect(lookSize(app(PadTrimmer::class)->trim(paddedLook(180, '#ececf2'))))->toBe([180, 400]);
});

it('leaves a look without margins byte for byte', function () {
    $full = paddedLook(300);

    expect(app(PadTrimmer::class)->trim($full))->toBe($full);
});

it('leaves an edge alone that is not the pad colour', function () {
    $white = paddedLook(180, '#ffffff');

    expect(app(PadTrimmer::class)->trim($white))->toBe($white);
});

it('refuses a trim that would leave too little of the image', function () {
    $sliver = paddedLook(60);

    expect(app(PadTrimmer::class)->trim($sliver))->toBe($sliver);
});

it('does nothing while aspect fitting is off, and never throws on bad bytes', function () {
    expect(app(PadTrimmer::class)->trim('not an image'))->toBe('not an image');

    config(['photo.output.aspect' => '']);
    $padded = paddedLook(180);

    expect(app(PadTrimmer::class)->trim($padded))->toBe($padded);
});

it('trims looks already in storage with looks:trim-padding', function () {
    Storage::fake('r2');
    config(['fitting_room.photo.disk' => 'r2']);
    Storage::disk('r2')->put('photos/a-look-1.png', paddedLook(180));
    Storage::disk('r2')->put('photos/b-look-1.png', paddedLook(300));
    Generation::factory()->done()->create(['result_keys' => ['photos/a-look-1.png']]);
    Generation::factory()->done()->create(['result_keys' => ['photos/b-look-1.png']]);
    Generation::factory()->done()->create(['result_keys' => ['photos/gone-look-1.png']]);

    $this->artisan('looks:trim-padding --dry-run')
        ->expectsOutputToContain('Would trim 1 look(s); 1 had no margins; 1 gone or unreadable.')
        ->assertSuccessful();
    expect(lookSize(Storage::disk('r2')->get('photos/a-look-1.png')))->toBe([300, 400]);

    $this->artisan('looks:trim-padding')
        ->expectsOutputToContain('Trimmed 1 look(s)')
        ->assertSuccessful();
    expect(lookSize(Storage::disk('r2')->get('photos/a-look-1.png')))->toBe([180, 400])
        ->and(lookSize(Storage::disk('r2')->get('photos/b-look-1.png')))->toBe([300, 400]);
});
