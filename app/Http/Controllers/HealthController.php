<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class HealthController extends Controller
{
    /**
     * Liveness/readiness probe.
     *
     * Confirms the application boots and that its two stateful backends —
     * PostgreSQL and Redis — are actually reachable. Used by CI to prove the
     * environment is wired correctly and by uptime monitoring in production.
     */
    public function show(): JsonResponse
    {
        $services = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
        ];

        $healthy = ! in_array(false, $services, true);

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'services' => array_map(fn (bool $up) => $up ? 'up' : 'down', $services),
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): bool
    {
        try {
            DB::connection()->select('select 1');

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    private function checkRedis(): bool
    {
        try {
            return (string) Redis::connection()->ping() !== '';
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }
}
