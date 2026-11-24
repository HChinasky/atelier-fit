<?php

use App\Models\ColorProfile;
use App\Models\User;

/*
 * The one-off backfill of users.color_profile_id for members analysed before
 * pinning existed. It must pick what AnalyzeColorProfile would have pinned —
 * and nothing else.
 */

function runBackfill(): void
{
    (require database_path('migrations/2026_09_27_140000_backfill_pinned_color_profiles.php'))->up();
}

it('pins the latest consented analysis that placed a season', function () {
    $member = User::factory()->emailOnly()->create();
    ColorProfile::factory()->classified('soft_summer')->create(['user_id' => $member->id, 'consented_at' => now(), 'created_at' => now()->subDays(3)]);
    $latest = ColorProfile::factory()->classified('deep_autumn')->create(['user_id' => $member->id, 'consented_at' => now(), 'created_at' => now()->subDay()]);
    ColorProfile::factory()->rejected()->create(['user_id' => $member->id, 'consented_at' => now()]);
    ColorProfile::factory()->measured()->create(['user_id' => $member->id, 'consented_at' => now()]);

    runBackfill();

    expect($member->fresh()->color_profile_id)->toBe($latest->id);
});

it('never pins an instrument upload without consent', function () {
    $admin = User::factory()->create();
    ColorProfile::factory()->classified()->create(['user_id' => $admin->id, 'consented_at' => null, 'person_ref' => 'model-7']);

    runBackfill();

    expect($admin->fresh()->color_profile_id)->toBeNull();
});

it('leaves a season pinned since alone, and can run twice', function () {
    $member = User::factory()->emailOnly()->create();
    $pinned = ColorProfile::factory()->classified('true_winter')->create(['user_id' => $member->id, 'consented_at' => now(), 'created_at' => now()->subDays(2)]);
    ColorProfile::factory()->classified('deep_autumn')->create(['user_id' => $member->id, 'consented_at' => now()]);
    $member->forceFill(['color_profile_id' => $pinned->id])->save();

    runBackfill();
    runBackfill();

    expect($member->fresh()->color_profile_id)->toBe($pinned->id);
});
