<?php

use Illuminate\Support\Facades\DB;

// Locks in the intentional test isolation required by CLAUDE.md: the suite
// must never depend on external queue/cache backends, and must run against a
// SEPARATE Postgres database — never the dev database, never sqlite.

it('runs against a dedicated Postgres test database (not the dev database)', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('testing');
});

it('isolates queue, cache and session from external services', function () {
    expect(config('queue.default'))->toBe('sync')
        ->and(config('cache.default'))->toBe('array')
        ->and(config('session.driver'))->toBe('array');
});

it('keeps a clean schema between tests', function () {
    // Default migrations are present (proves migrations ran on the test DB)...
    expect(DB::getSchemaBuilder()->hasTable('users'))->toBeTrue();

    // ...and writes here are rolled back by RefreshDatabase, so the next test
    // never sees this row. The companion test below asserts the empty state.
    DB::table('users')->insert([
        'name' => 'leak-probe',
        'email' => 'leak-probe@example.test',
        'password' => bcrypt('secret'),
    ]);

    expect(DB::table('users')->where('email', 'leak-probe@example.test')->exists())->toBeTrue();
});

it('does not leak rows from the previous test', function () {
    expect(DB::table('users')->count())->toBe(0);
});
