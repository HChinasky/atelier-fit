<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

it('boots and reports Postgres and Redis as live', function () {
    $response = $this->getJson('/health');

    $response
        ->assertOk()
        ->assertJson([
            'status' => 'ok',
            'services' => [
                'database' => 'up',
                'redis' => 'up',
            ],
        ]);
});

it('actually runs on PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    // A real round-trip against the live Postgres test database.
    expect(DB::connection()->select('select 1 as one')[0]->one)->toEqual(1);
});

it('can round-trip a value through the live Redis connection', function () {
    Redis::connection()->set('health:probe', 'pong');

    expect((string) Redis::connection()->get('health:probe'))->toBe('pong');

    Redis::connection()->del('health:probe');
});
