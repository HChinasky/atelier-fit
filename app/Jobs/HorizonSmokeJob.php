<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

/**
 * Diagnostic job used by `php artisan app:horizon-smoke` to prove that the
 * Redis queue is actually drained by a running Horizon worker (not sync,
 * not the database driver). It is intentionally side-effecting: on handle it
 * records who processed it (pid + host + timestamp) into a short-lived Redis
 * key the command then reads back. Not part of the domain — diagnostics only.
 */
class HorizonSmokeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $token) {}

    public static function resultKey(string $token): string
    {
        return "horizon-smoke:result:{$token}";
    }

    public function handle(): void
    {
        $payload = [
            'token' => $this->token,
            'processed_at' => now()->toIso8601String(),
            'worker_pid' => getmypid(),
            'hostname' => gethostname(),
        ];

        // Side-effect = evidence. TTL keeps Redis clean if nobody reads it.
        Redis::connection()->setex(self::resultKey($this->token), 300, json_encode($payload));

        Log::info('[horizon-smoke] job processed by worker', $payload);
    }
}
