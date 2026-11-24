<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

// Proves the Laravel model-factory infrastructure works end-to-end against the
// isolated Postgres test database: records are built through the factory (never
// raw inserts), Faker produces values, and factory states apply. This is the
// pattern every domain model in later phases must follow (see CLAUDE.md).

it('persists a valid User created through its factory', function () {
    $user = User::factory()->create();

    // Persisted in the test DB (real row, real auto-increment id).
    expect($user->exists)->toBeTrue()
        ->and($user->getKey())->toBeGreaterThan(0)
        ->and(User::whereKey($user->getKey())->exists())->toBeTrue()
        ->and(DB::table('users')->count())->toBe(1);

    // Faker-generated, valid attributes.
    expect($user->name)->toBeString()->not->toBeEmpty()
        ->and($user->email)->toMatch('/^.+@.+\..+$/')
        ->and($user->email_verified_at)->not->toBeNull();
});

it('generates distinct fake data per record', function () {
    $users = User::factory()->count(3)->create();

    expect($users->pluck('email')->unique())->toHaveCount(3);
});

it('applies factory states (unverified)', function () {
    $user = User::factory()->unverified()->create();

    expect($user->email_verified_at)->toBeNull()
        ->and(User::whereNull('email_verified_at')->count())->toBe(1);
});
