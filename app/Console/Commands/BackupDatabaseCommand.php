<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Takes a database backup and prunes old ones.
 *
 * Until now "backups" meant a dump somebody remembered to run before a risky
 * change, left on the same server as the database it was protecting — no use
 * against losing the machine, and worse, a copy of personal data sitting outside
 * every retention rule we publish. This puts them on a schedule, off the box, and
 * inside a retention period.
 *
 * The dump goes to the private object store under its own prefix, so the photo
 * lifecycle rule cannot reach it and the photo sweep cannot mistake it for an
 * expired image. Anything older than the configured period is deleted here, which
 * is what makes the promise in the privacy policy true: an erased record survives
 * in backups only until the backups holding it age out.
 *
 * The database password is read from the app's own configuration and handed to
 * pg_dump through the environment, never as an argument (arguments are visible in
 * the process list) and never in the systemd unit.
 */
class BackupDatabaseCommand extends Command
{
    protected $signature = 'app:backup-database
                            {--prune-only : Delete expired backups without taking a new one}';

    protected $description = 'Dump the database to private storage and delete backups past their retention';

    public function handle(): int
    {
        $disk = (string) config('backup.disk');
        $prefix = trim((string) config('backup.prefix'), '/');

        if (! $this->option('prune-only')) {
            try {
                $key = $this->dump($disk, $prefix);
            } catch (Throwable $e) {
                // The message can carry the connection string; keep it out of logs.
                $this->error('Backup failed: '.class_basename($e));
                Log::error('[backup] database dump failed', ['error' => class_basename($e)]);

                return self::FAILURE;
            }

            $this->info(sprintf('Backup written: %s (%.1f MB)', $key, Storage::disk($disk)->size($key) / 1048576));
        }

        $pruned = $this->prune($disk, $prefix);

        $this->line(sprintf('Expired backups deleted: %d', $pruned));
        Log::info('[backup] finished', ['pruned' => $pruned, 'dump_taken' => ! $this->option('prune-only')]);

        return self::SUCCESS;
    }

    /**
     * Run pg_dump and stream the result into object storage.
     *
     * Custom format (-Fc) because it restores selectively and compresses itself;
     * a plain SQL text dump of the same data is several times larger.
     *
     * @return string the storage key written
     */
    private function dump(string $disk, string $prefix): string
    {
        $db = config('database.connections.'.config('database.default'));

        $process = new Process([
            (string) config('backup.pg_dump_path'),
            '--host='.$db['host'],
            '--port='.$db['port'],
            '--username='.$db['username'],
            '--dbname='.$db['database'],
            '--format=custom',
            '--no-owner',
            '--no-acl',
        ], null, ['PGPASSWORD' => (string) ($db['password'] ?? '')], null, (int) config('backup.timeout'));

        $process->mustRun();

        // Sorted by name = sorted by time, which is what the prune below relies on.
        $key = sprintf('%s/%s-%s.dump', $prefix, $db['database'], now()->format('Y-m-d_His'));

        Storage::disk($disk)->put($key, $process->getOutput(), ['visibility' => 'private']);

        return $key;
    }

    /**
     * Delete backups older than the retention period.
     *
     * Age comes from the object's own last-modified time rather than its name, so
     * a file uploaded by hand is treated like any other.
     */
    private function prune(string $disk, string $prefix): int
    {
        $days = (int) config('backup.retention_days');

        if ($days <= 0) {
            $this->warn('backup.retention_days is '.$days.' — pruning disabled, backups will accumulate.');

            return 0;
        }

        $cutoff = now()->subDays($days)->getTimestamp();
        $deleted = 0;

        foreach (Storage::disk($disk)->files($prefix) as $path) {
            try {
                if (Storage::disk($disk)->lastModified($path) >= $cutoff) {
                    continue;
                }

                Storage::disk($disk)->delete($path);
                $deleted++;
            } catch (Throwable $e) {
                // One unreadable object must not stop the rest from being pruned.
                $this->warn('Could not delete one expired backup.');
            }
        }

        return $deleted;
    }
}
