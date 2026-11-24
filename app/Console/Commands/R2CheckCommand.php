<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Manual operator tool: exercises a real put → get → verify → delete
 * round-trip against the live Cloudflare R2 bucket using the configured
 * R2_* credentials. This is NOT a test — it intentionally hits the network
 * and the real bucket. Tests keep R2 mocked via Storage::fake('r2').
 *
 * Usage (after filling R2_* in .env):
 *   php artisan r2:check
 */
class R2CheckCommand extends Command
{
    protected $signature = 'r2:check {--keep : Do not delete the probe object at the end}';

    protected $description = 'Live put/get/delete round-trip against the real R2 bucket (manual check, not a test)';

    public function handle(): int
    {
        // Read everything through config() (config/filesystems.php), never env()
        // directly — env() returns null once the config is cached (config:cache).
        $config = config('filesystems.disks.r2');

        // config key => the .env var that feeds it (used only for the message).
        $required = [
            'key' => 'R2_ACCESS_KEY_ID',
            'secret' => 'R2_SECRET_ACCESS_KEY',
            'bucket' => 'R2_BUCKET',
            'endpoint' => 'R2_ENDPOINT',
        ];

        foreach ($required as $configKey => $envName) {
            if (blank($config[$configKey] ?? null)) {
                $this->error("Missing {$envName}. Fill the R2_* values in .env before running r2:check.");

                return self::FAILURE;
            }
        }

        $disk = Storage::disk('r2');
        $path = 'healthchecks/r2-'.now()->format('Ymd-His').'-'.Str::random(8).'.txt';
        $expected = 'r2-roundtrip-'.Str::uuid();

        $this->line('Bucket:   <comment>'.config('filesystems.disks.r2.bucket').'</comment>');
        $this->line('Endpoint: <comment>'.config('filesystems.disks.r2.endpoint').'</comment>');
        $this->line('Object:   <comment>'.$path.'</comment>');
        $this->newLine();

        try {
            $this->line('PUT ...');
            $disk->put($path, $expected);

            $this->line('GET ...');
            $actual = $disk->get($path);

            if ($actual !== $expected) {
                $this->error('Mismatch: content read back does not equal content written.');
                $this->line('  expected: '.$expected);
                $this->line('  actual:   '.var_export($actual, true));

                return self::FAILURE;
            }
            $this->info('Content verified: written value matches value read back.');

            if ($this->option('keep')) {
                $this->warn('Leaving probe object in the bucket (--keep): '.$path);
            } else {
                $this->line('DELETE ...');
                $disk->delete($path);

                if ($disk->exists($path)) {
                    $this->error('Delete failed: object still exists after delete.');

                    return self::FAILURE;
                }
                $this->info('Object deleted; bucket clean.');
            }
        } catch (Throwable $e) {
            $this->error('R2 round-trip failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('PASS: live R2 round-trip (put → get → verify → delete) succeeded.');

        return self::SUCCESS;
    }
}
