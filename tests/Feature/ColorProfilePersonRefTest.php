<?php

use App\Models\ColorProfile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Which person a profile is of, and what light it was shot in.
 *
 * Calibrating white balance needs the same face under different lighting, and
 * nothing else in a colour profile says that two of them are the same face.
 */

beforeEach(function () {
    Storage::fake('r2');
    config(['features.color_analysis' => true]);
    Queue::fake();
});

it('records the handle and the lighting from the upload', function () {
    $user = User::factory()->create(['is_admin' => true]);

    $this->actingAs($user)->postJson('/admin/color', [
        'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 900),
        'person_ref' => 'p001',
        'lighting' => 'indoor_warm',
    ])->assertCreated();

    $profile = ColorProfile::sole();
    expect($profile->person_ref)->toBe('p001')
        ->and($profile->lighting)->toBe('indoor_warm');
});

it('leaves both blank for a one-off analysis', function () {
    /* Most uploads are not part of a calibration set and should not be made to
     * invent a handle. */
    $user = User::factory()->create(['is_admin' => true]);

    $this->actingAs($user)->postJson('/admin/color', [
        'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 900),
    ])->assertCreated();

    $profile = ColorProfile::sole();
    expect($profile->person_ref)->toBeNull()->and($profile->lighting)->toBeNull();
});

it('refuses a lighting the engine does not use', function () {
    /* The same vocabulary as the engine's fixture filenames, or a calibration
     * set gathered here and one gathered on disk are not the same set. */
    $user = User::factory()->create(['is_admin' => true]);

    $this->actingAs($user)->postJson('/admin/color', [
        'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 900),
        'lighting' => 'candlelight',
    ])->assertStatus(422);
});

it('refuses a handle that is not a plain handle', function () {
    /* It goes into an export as-is. `p001` is safe there precisely because it
     * is not a name, and the validation is what keeps it that way. */
    $user = User::factory()->create(['is_admin' => true]);

    $this->actingAs($user)->postJson('/admin/color', [
        'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 900),
        'person_ref' => 'Olga Petrova',
    ])->assertStatus(422);
});

it('groups several shots of the same person', function () {
    ColorProfile::factory()->forPerson('p001', 'daylight')->create();
    ColorProfile::factory()->forPerson('p001', 'indoor_warm')->create();
    ColorProfile::factory()->forPerson('p002', 'daylight')->create();

    expect(ColorProfile::where('person_ref', 'p001')->count())->toBe(2);
});
