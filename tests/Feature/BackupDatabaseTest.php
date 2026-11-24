<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

/*
 * app:backup-database — the pruning half.
 *
 * Taking the dump is pg_dump's job and is not re-tested here; what matters for
 * privacy is the other half. A backup is a copy of personal data that outlives
 * the erasure of the original, so the window in which "deleted" is not quite
 * deleted is exactly the retention period enforced below.
 */

beforeEach(function () {
    Storage::fake('r2');
    config(['backup.disk' => 'r2', 'backup.prefix' => 'backups', 'backup.retention_days' => 30]);
});

/** A stored backup, backdated to a given age in days. */
function agedBackup(string $name, int $daysOld): string
{
    $key = 'backups/'.$name;
    Storage::disk('r2')->put($key, 'DUMP');
    touch(Storage::disk('r2')->path($key), now()->subDays($daysOld)->getTimestamp());

    return $key;
}

it('deletes backups past the retention period', function () {
    $old = agedBackup('fitting_room-2026-06-01_020000.dump', 31);

    $this->artisan('app:backup-database', ['--prune-only' => true])->assertSuccessful();

    Storage::disk('r2')->assertMissing($old);
});

it('keeps backups inside the retention period', function () {
    $fresh = agedBackup('fitting_room-2026-08-20_020000.dump', 29);

    $this->artisan('app:backup-database', ['--prune-only' => true])->assertSuccessful();

    Storage::disk('r2')->assertExists($fresh);
});

it('honours a changed retention period from config', function () {
    $backup = agedBackup('fitting_room-2026-08-15_020000.dump', 10);

    $this->artisan('app:backup-database', ['--prune-only' => true])->assertSuccessful();
    Storage::disk('r2')->assertExists($backup);

    config(['backup.retention_days' => 7]);
    $this->artisan('app:backup-database', ['--prune-only' => true])->assertSuccessful();
    Storage::disk('r2')->assertMissing($backup);
});

it('treats a retention of 0 as "keep everything" rather than "delete everything"', function () {
    $ancient = agedBackup('fitting_room-2026-01-01_020000.dump', 400);

    config(['backup.retention_days' => 0]);

    $this->artisan('app:backup-database', ['--prune-only' => true])->assertSuccessful();

    Storage::disk('r2')->assertExists($ancient);
});

it('leaves the photos alone — backups live under their own prefix', function () {
    // The photo sweep walks `uploads` by age and would happily delete a dump if
    // the two shared a prefix; this keeps the two clocks apart.
    Storage::disk('r2')->put('uploads/2026-08-20/photo.jpg', 'PHOTO');
    touch(Storage::disk('r2')->path('uploads/2026-08-20/photo.jpg'), now()->subDays(400)->getTimestamp());

    $this->artisan('app:backup-database', ['--prune-only' => true])->assertSuccessful();

    Storage::disk('r2')->assertExists('uploads/2026-08-20/photo.jpg');
});

it('runs daily, before the retention sweep', function () {
    $events = collect(app(Schedule::class)->events());

    $backup = $events->first(fn ($e) => str_contains($e->command ?? '', 'app:backup-database'));
    $cleanup = $events->first(fn ($e) => str_contains($e->command ?? '', 'app:cleanup-expired-data'));

    expect($backup)->not->toBeNull()
        ->and($backup->expression)->toBe('30 2 * * *')
        // A dump taken after the sweep would be the only remaining copy of rows
        // the sweep had just deleted for good.
        ->and($cleanup->expression)->toBe('30 3 * * *');
});
