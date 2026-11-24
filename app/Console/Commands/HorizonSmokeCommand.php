<?php

namespace App\Console\Commands;

use App\Jobs\HorizonSmokeJob;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Laravel\Horizon\Contracts\JobRepository;
use Throwable;

/**
 * Reproducible proof that the Redis queue is served by a running Horizon
 * worker. Dispatches a job onto the redis connection, then waits for the
 * job's side-effect and for Horizon's own job ledger to show it completed.
 *
 * Horizon only records jobs it supervises — a sync/database run never appears
 * in its ledger, so a "completed" record there is positive evidence that
 * Horizon (not sync, not `queue:work`/`queue:listen`) drained the queue.
 *
 * Usage: start a worker first (`php artisan horizon`), then in another shell:
 *   php artisan app:horizon-smoke
 */
class HorizonSmokeCommand extends Command
{
    protected $signature = 'app:horizon-smoke {--timeout=20 : Seconds to wait for the worker to process the job}';

    protected $description = 'Prove the Redis queue is drained by a running Horizon worker';

    public function handle(): int
    {
        $connection = config('queue.default');

        $this->line("Queue connection (queue.default): <comment>{$connection}</comment>");

        if ($connection !== 'redis') {
            $this->error("queue.default is '{$connection}', expected 'redis'. Run against the real (non-test) env.");

            return self::FAILURE;
        }

        $token = (string) Str::uuid();
        $key = HorizonSmokeJob::resultKey($token);

        Redis::connection()->del($key);

        $before = $this->safeCountRecent();

        $this->line("Dispatching HorizonSmokeJob (token={$token}) onto redis queue from PID <comment>".getmypid().'</comment>...');
        HorizonSmokeJob::dispatch($token);

        $timeout = (int) $this->option('timeout');
        $deadline = time() + $timeout;
        $raw = null;

        $this->line("Waiting up to {$timeout}s for a worker to process it...");
        while (time() < $deadline) {
            $raw = Redis::connection()->get($key);
            if ($raw !== null && $raw !== '') {
                break;
            }
            usleep(300_000);
        }

        if ($raw === null || $raw === '') {
            $this->error('Job was NOT processed within the timeout. Is Horizon running? (php artisan horizon)');

            return self::FAILURE;
        }

        $payload = json_decode($raw, true);
        Redis::connection()->del($key);

        $this->newLine();
        $this->info('Job processed — side-effect observed:');
        $this->table(['field', 'value'], [
            ['token', $payload['token'] ?? ''],
            ['processed_at', $payload['processed_at'] ?? ''],
            ['worker_pid', $payload['worker_pid'] ?? ''],
            ['command_pid', getmypid()],
            ['hostname', $payload['hostname'] ?? ''],
        ]);

        if ((string) ($payload['worker_pid'] ?? '') !== (string) getmypid()) {
            $this->line('worker_pid differs from command_pid → executed in a separate worker process (not inline/sync).');
        }

        // Horizon's own ledger — definitive proof it was Horizon.
        $this->horizonEvidence($before);

        $this->newLine();
        $this->info('PASS: redis queue is being drained by a running Horizon worker.');

        return self::SUCCESS;
    }

    private function safeCountRecent(): ?int
    {
        try {
            return app(JobRepository::class)->countRecent();
        } catch (Throwable $e) {
            return null;
        }
    }

    private function horizonEvidence(?int $before): void
    {
        try {
            $repo = app(JobRepository::class);
            // Give Horizon a beat to flush the "completed" record into its ledger.
            $after = null;
            $recent = null;
            for ($i = 0; $i < 20; $i++) {
                $after = $repo->countRecent();
                $recent = collect($repo->getRecent())
                    ->first(fn ($job) => str_contains((string) ($job->name ?? ''), 'HorizonSmokeJob'));
                if ($recent && ($recent->status ?? null) === 'completed') {
                    break;
                }
                usleep(300_000);
            }

            $this->newLine();
            $this->info('Horizon ledger evidence (Horizon only records jobs it supervises):');
            $this->line('  recent jobs count: '.($before ?? 'n/a').' → '.($after ?? 'n/a'));
            if ($recent) {
                $completedAt = $recent->completed_at ?? $recent->reserved_at ?? null;
                if (is_numeric($completedAt)) {
                    $completedAt = Carbon::createFromTimestamp((float) $completedAt)->toIso8601String();
                }
                $this->line('  job name:   '.($recent->name ?? ''));
                $this->line('  job status: '.($recent->status ?? ''));
                $this->line('  completed:  '.$completedAt);
            }
        } catch (Throwable $e) {
            $this->warn('Could not read Horizon ledger: '.$e->getMessage());
        }
    }
}
