<?php

use App\Jobs\ImportFeedJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Daily catalog import. Schedule::job() dispatches onto the Redis queue, so the
// work is done by Horizon (the project's sole queue worker) — not run inline by
// the scheduler. withoutOverlapping() prevents a new run stacking on a slow one.
// Only once a feed is configured: while the partner network is in moderation
// there is no FEED_URL and the catalog is published from a file
// (catalog:import-file), so a nightly fetch of "" would only fail and log.
Schedule::job(new ImportFeedJob)
    ->daily()
    ->when(fn () => filled(config('feed.url')))
    ->withoutOverlapping()
    ->name('import-feed');

// Retention sweep: deletes personal data whose published period has passed
// (config/privacy.php). Runs in the scheduler process rather than on the queue —
// it is short, must not compete with generation workers, and its report belongs in
// one place. withoutOverlapping() guards against a slow run being stacked on.
Schedule::command('app:cleanup-expired-data')
    ->dailyAt('03:30')
    ->withoutOverlapping()
    ->name('cleanup-expired-data');

// Photo hard delete. HOURLY, unlike the retention sweep above: the deadline it
// protects is published in hours (72), so a daily pass would leave a photo
// uploaded just after a run unconsidered for another 24 — past the promise. This
// is what actually deletes the images; the bucket lifecycle rule is the backstop.
Schedule::command('app:purge-expired-photos')
    ->hourly()
    ->withoutOverlapping()
    ->name('purge-expired-photos');

// Database backup, off the machine that holds the database, pruned to the
// published retention period. Runs before the retention sweep so a dump never
// captures rows the sweep was about to delete for the last time.
Schedule::command('app:backup-database')
    ->dailyAt('02:30')
    ->withoutOverlapping()
    ->name('backup-database');

// Garment colours for the catalog: queues only the products that need it
// (new, re-photographed, or last attempt failed) onto the colour queue. After
// the midnight import, so the day's new products are measured the same night;
// anything the import is still writing at 01:30 is picked up the next night.
Schedule::command('catalog:measure-colors')
    ->dailyAt('01:30')
    ->withoutOverlapping()
    ->name('measure-catalog-colors');
