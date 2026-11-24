<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

// The R2 disk is the future home of client photos and FASHN results. We never
// hit real Cloudflare R2 from tests (money + private data); the disk is faked.
// These tests prove the "r2" disk is registered and behaves as an S3 disk so
// later phases can rely on write/read/delete round-trips.

it('registers a dedicated r2 filesystem disk backed by the s3 driver', function () {
    expect(config('filesystems.disks.r2.driver'))->toBe('s3')
        ->and(config('filesystems.disks.r2.region'))->toBe('auto')
        ->and(config('filesystems.disks.r2.use_path_style_endpoint'))->toBeTrue();
});

it('round-trips a file through the r2 disk', function () {
    Storage::fake('r2');

    $photo = UploadedFile::fake()->image('client.jpg');
    $path = $photo->storeAs('photos', 'client.jpg', 'r2');

    expect($path)->toBe('photos/client.jpg');
    Storage::disk('r2')->assertExists('photos/client.jpg');

    expect(Storage::disk('r2')->get('photos/client.jpg'))->not->toBeNull();
});

it('removes files from the r2 disk', function () {
    Storage::fake('r2');

    Storage::disk('r2')->put('tmp/scratch.txt', 'hello');
    Storage::disk('r2')->assertExists('tmp/scratch.txt');

    Storage::disk('r2')->delete('tmp/scratch.txt');
    Storage::disk('r2')->assertMissing('tmp/scratch.txt');
});
