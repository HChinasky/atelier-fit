<?php

use App\Models\ColorProfile;
use App\Models\ColorProfileImage;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/*
 * The colour profile and its images.
 *
 * The split between the two tables is the thing worth testing: the profile holds
 * numbers and outlives the purge, the images hold personal data on a 60-hour
 * clock and do not.
 */

beforeEach(function () {
    Storage::fake('r2');
    config(['privacy.photo_hard_delete_hours' => 60]);
});

it('starts pending and settles once the engine has answered', function () {
    $pending = ColorProfile::factory()->create();

    expect($pending->isPending())->toBeTrue()
        ->and($pending->isSettled())->toBeFalse();

    expect(ColorProfile::factory()->measured()->create()->isSettled())->toBeTrue()
        ->and(ColorProfile::factory()->rejected()->create()->isSettled())->toBeTrue()
        ->and(ColorProfile::factory()->failed()->create()->isSettled())->toBeTrue();
});

it('keeps a rejection apart from a failure', function () {
    /* The engine looked and said no, versus our environment broke. Collapsing
     * the two would tell a member to retake a photo because a service was down. */
    $rejected = ColorProfile::factory()->rejected('low_light')->create();
    $failed = ColorProfile::factory()->failed('pose-service unreachable')->create();

    expect($rejected->reject_reason)->toBe('low_light')
        ->and($rejected->error)->toBeNull()
        ->and($failed->reject_reason)->toBeNull()
        ->and($failed->error)->toBe('pose-service unreachable');
});

it('stores the engine samples as structured numbers', function () {
    $profile = ColorProfile::factory()->measured()->create();

    $samples = $profile->fresh()->samples;
    expect($samples['skin'])->toHaveKeys(['L', 'a', 'b', 'C', 'h', 'pixels'])
        ->and($samples['skin_parts'])->toHaveKeys(['left_cheek', 'right_cheek', 'forehead'])
        ->and($profile->fresh()->white_balance['method'])->toBe('sclera')
        ->and($profile->fresh()->target_calibrated)->toBeFalse();
});

it('has no season yet, and says so with a null rather than a guess', function () {
    $profile = ColorProfile::factory()->measured()->create();

    expect($profile->season)->toBeNull()
        ->and($profile->confidence)->toBeNull();
});

it('belongs to a user and goes when they do', function () {
    $user = User::factory()->create();
    $profile = ColorProfile::factory()->for($user)->create();
    ColorProfileImage::factory()->for($profile, 'profile')->create();

    expect($profile->user->is($user))->toBeTrue()
        ->and($profile->images)->toHaveCount(1);

    $user->delete();

    expect(ColorProfile::count())->toBe(0)
        ->and(ColorProfileImage::count())->toBe(0);
});

it('stores images under the shared photo prefix so the existing sweep finds them', function () {
    /* Nothing had to be taught about this feature: the sweep lists the bucket
     * under one prefix, so living there is what buys the 60-hour clock. */
    $image = ColorProfileImage::factory()->create();

    expect($image->path)->toStartWith((string) config('fitting_room.photo.prefix').'/')
        ->and($image->disk)->toBe((string) config('fitting_room.photo.disk'));
});

it('drops image rows once their clock has passed', function () {
    $fresh = ColorProfileImage::factory()->create();
    $stale = ColorProfileImage::factory()->expired()->create();

    $this->artisan('app:purge-expired-photos')->assertSuccessful();

    expect(ColorProfileImage::find($fresh->id))->not->toBeNull()
        ->and(ColorProfileImage::find($stale->id))->toBeNull();
});

it('leaves the measured numbers behind when the images go', function () {
    /* The promise: after the purge the profile is still a complete record of
     * the analysis, and carries nothing that is a picture of anybody. */
    $profile = ColorProfile::factory()->measured()->create();
    ColorProfileImage::factory()->for($profile, 'profile')->expired()->create();

    $this->artisan('app:purge-expired-photos')->assertSuccessful();

    $profile->refresh();
    expect($profile->images)->toHaveCount(0)
        ->and($profile->samples['skin']['L'])->toBe(70.1)
        ->and($profile->algo_version)->toBe('0.1.0');
});

it('counts rows without deleting them on a dry run', function () {
    ColorProfileImage::factory()->expired()->create();

    $this->artisan('app:purge-expired-photos', ['--dry-run' => true])->assertSuccessful();

    expect(ColorProfileImage::count())->toBe(1);
});

it('will not let an admin flag be mass-assigned', function () {
    /* One careless ->fill($request->all()) must not be able to make somebody an
     * administrator. The flag is set by hand in the database, on purpose. */
    $user = User::factory()->create();

    $user->fill(['is_admin' => true]);

    expect($user->isAdmin())->toBeFalse();
});

it('reads the admin flag as a boolean', function () {
    $user = User::factory()->create();
    $user->forceFill(['is_admin' => true])->save();

    expect($user->fresh()->isAdmin())->toBeTrue()
        ->and(User::factory()->create()->isAdmin())->toBeFalse();
});

// --- retention -----------------------------------------------------------

it('deletes a colour profile once its retention period has passed', function () {
    /* The photo went in hours. These are the numbers left behind — still
     * measurements of somebody's body, so they get a period of their own
     * rather than living forever because nobody thought about them. */
    config(['privacy.retention.color_profile_days' => 90]);
    $fresh = ColorProfile::factory()->measured()->create();
    $stale = ColorProfile::factory()->measured()->create(['created_at' => now()->subDays(91)]);

    $this->artisan('app:cleanup-expired-data')->assertSuccessful();

    expect(ColorProfile::find($fresh->id))->not->toBeNull()
        ->and(ColorProfile::find($stale->id))->toBeNull();
});

it('takes any surviving image rows with the profile', function () {
    /* The cascade is what stops a row outliving the object it points at. */
    config(['privacy.retention.color_profile_days' => 90]);
    $stale = ColorProfile::factory()->create(['created_at' => now()->subDays(91)]);
    ColorProfileImage::factory()->for($stale, 'profile')->create();

    $this->artisan('app:cleanup-expired-data')->assertSuccessful();

    expect(ColorProfileImage::count())->toBe(0);
});

it('counts profiles without deleting them on a dry run', function () {
    config(['privacy.retention.color_profile_days' => 90]);
    ColorProfile::factory()->create(['created_at' => now()->subDays(91)]);

    $this->artisan('app:cleanup-expired-data', ['--dry-run' => true])->assertSuccessful();

    expect(ColorProfile::count())->toBe(1);
});

it('keeps everything when the retention rule is switched off', function () {
    /* Zero disables the rule rather than meaning "immediately" — a mistyped env
     * must keep data, not destroy it, which is how every other rule here
     * behaves. */
    config(['privacy.retention.color_profile_days' => 0]);
    ColorProfile::factory()->create(['created_at' => now()->subYears(5)]);

    $this->artisan('app:cleanup-expired-data')
        ->expectsOutputToContain('rule disabled')
        ->assertSuccessful();

    expect(ColorProfile::count())->toBe(1);
});

it('ships the documented default retention', function () {
    expect(config('privacy.retention.color_profile_days'))->toBe(90);
});
