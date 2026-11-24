<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

/*
 * app:purge-expired-photos — the application deleting images itself, rather than
 * trusting the bucket lifecycle rule to do it on time.
 *
 * The threshold is deliberately BELOW the published 72 hours, so these tests are
 * written against the configured value rather than a hardcoded 72.
 */

beforeEach(function () {
    Storage::fake('r2');
    config(['privacy.photo_hard_delete_hours' => 60]);
});

/** Put an object in storage and backdate it to a given age in hours. */
function agedObject(string $key, int $hoursOld): string
{
    Storage::fake('r2');
    Storage::disk('r2')->put($key, 'BYTES');

    // The fake disk exposes the real filesystem, so the object's own mtime — the
    // same signal the sweep reads — can be moved back.
    touch(Storage::disk('r2')->path($key), now()->subHours($hoursOld)->getTimestamp());

    return $key;
}

it('deletes an image older than the hard-delete threshold', function () {
    agedObject('uploads/2026-08-20/old.jpg', 61);

    $this->artisan('app:purge-expired-photos')->assertSuccessful();

    Storage::disk('r2')->assertMissing('uploads/2026-08-20/old.jpg');
});

it('keeps an image that has not reached the threshold yet', function () {
    agedObject('uploads/2026-08-24/fresh.jpg', 59);

    $this->artisan('app:purge-expired-photos')->assertSuccessful();

    Storage::disk('r2')->assertExists('uploads/2026-08-24/fresh.jpg');
});

it('deletes results as well as source photos', function () {
    Storage::disk('r2')->put('uploads/2026-08-20/photo.jpg', 'PHOTO');
    Storage::disk('r2')->put('uploads/2026-08-20/abc-look-1.png', 'RESULT');
    touch(Storage::disk('r2')->path('uploads/2026-08-20/photo.jpg'), now()->subHours(70)->getTimestamp());
    touch(Storage::disk('r2')->path('uploads/2026-08-20/abc-look-1.png'), now()->subHours(70)->getTimestamp());

    $this->artisan('app:purge-expired-photos')->assertSuccessful();

    expect(Storage::disk('r2')->allFiles('uploads'))->toBeEmpty();
});

it('deletes an abandoned upload that never became a generation', function () {
    // Nothing in the database points at this object — a DB-driven sweep would walk
    // straight past it, and the promise would quietly not apply to people who
    // uploaded a photo and then closed the tab.
    agedObject('uploads/2026-08-20/orphan.jpg', 65);

    $this->artisan('app:purge-expired-photos')->assertSuccessful();

    Storage::disk('r2')->assertMissing('uploads/2026-08-20/orphan.jpg');
});

it('honours a changed threshold from config without a code change', function () {
    agedObject('uploads/2026-08-24/photo.jpg', 30);

    $this->artisan('app:purge-expired-photos')->assertSuccessful();
    Storage::disk('r2')->assertExists('uploads/2026-08-24/photo.jpg');

    config(['privacy.photo_hard_delete_hours' => 24]);
    $this->artisan('app:purge-expired-photos')->assertSuccessful();
    Storage::disk('r2')->assertMissing('uploads/2026-08-24/photo.jpg');
});

it('keeps the threshold below the 72 hours the privacy policy promises', function () {
    // Headroom is the whole point: physical deletion has to land inside 72h even
    // if a run is missed, so the default must leave room for that.
    $default = (int) config('privacy.photo_hard_delete_hours');

    expect($default)->toBeGreaterThan(0)
        ->and($default)->toBeLessThan((int) config('fitting_room.photo.ttl_hours'));
});

it('treats a threshold of 0 as "sweep disabled" rather than "delete everything"', function () {
    agedObject('uploads/2026-01-01/ancient.jpg', 5000);

    config(['privacy.photo_hard_delete_hours' => 0]);

    $this->artisan('app:purge-expired-photos')->assertSuccessful();

    Storage::disk('r2')->assertExists('uploads/2026-01-01/ancient.jpg');
});

it('deletes nothing on a dry run', function () {
    agedObject('uploads/2026-08-20/old.jpg', 80);

    $this->artisan('app:purge-expired-photos', ['--dry-run' => true])->assertSuccessful();

    Storage::disk('r2')->assertExists('uploads/2026-08-20/old.jpg');
});

it('runs hourly, not daily — a daily pass would overshoot the promise', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'app:purge-expired-photos'));

    expect($events)->not->toBeEmpty()
        ->and($events->first()->expression)->toBe('0 * * * *');
});
