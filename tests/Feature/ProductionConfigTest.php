<?php

// Proves the NON-test (production / operator) configuration routes queue,
// cache and session through Redis — and that pgsql is the database driver.
//
// We deliberately do NOT read the live runtime config here: the test env
// overrides those drivers to sync/array on purpose (isolation). Instead we
// assert the documented operator baseline (.env.example) — the file an
// operator copies to .env on deploy — and confirm the config files actually
// define the redis-backed connections those values select. No live Redis,
// no weakening of test isolation.

function envExample(): array
{
    $values = [];
    foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $values[trim($key)] = trim($value, " \t\"'");
    }

    return $values;
}

it('ships an operator baseline that routes queue, cache and session through Redis', function () {
    $env = envExample();

    expect($env)
        ->toHaveKey('QUEUE_CONNECTION', 'redis')
        ->toHaveKey('CACHE_STORE', 'redis')
        ->toHaveKey('SESSION_DRIVER', 'redis')
        ->toHaveKey('REDIS_CLIENT', 'predis')
        ->toHaveKey('DB_CONNECTION', 'pgsql');
});

it('defines the redis-backed connections those baseline values resolve to', function () {
    // QUEUE_CONNECTION=redis -> queue.connections.redis (driver redis)
    expect(config('queue.connections.redis.driver'))->toBe('redis');

    // CACHE_STORE=redis -> cache.stores.redis (driver redis)
    expect(config('cache.stores.redis.driver'))->toBe('redis');

    // SESSION_DRIVER=redis is a valid Laravel session handler, backed by the
    // redis database connections defined here.
    expect(config('database.redis.default'))->toBeArray()
        ->and(config('database.redis.cache'))->toBeArray();
});

it('does not fall back to database/sync/file for the queue/cache defaults', function () {
    $env = envExample();

    expect($env['QUEUE_CONNECTION'])->not->toBeIn(['sync', 'database']);
    expect($env['CACHE_STORE'])->not->toBeIn(['database', 'file', 'array']);
    expect($env['SESSION_DRIVER'])->not->toBeIn(['database', 'file', 'array', 'cookie']);
});
