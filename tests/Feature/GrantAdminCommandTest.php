<?php

use App\Models\User;

/*
 * user:admin — the only way the administrator flag is ever set.
 *
 * There is deliberately no path from the interface, so this command is the
 * whole surface. Two things matter: it touches the right row, and it never
 * writes the address down.
 */

it('grants the flag', function () {
    $user = User::factory()->create(['email' => 'admin@example.test']);

    $exit = Artisan::call('user:admin', ['email' => 'admin@example.test']);

    expect($exit)->toBe(0)
        ->and(Artisan::output())->toContain('Account #'.$user->id.' is now an administrator')
        ->and($user->fresh()->isAdmin())->toBeTrue();
});

it('takes it away again', function () {
    $user = User::factory()->create(['email' => 'admin@example.test']);
    $user->forceFill(['is_admin' => true])->save();

    $this->artisan('user:admin', ['email' => 'admin@example.test', '--revoke' => true])
        ->expectsOutputToContain('no longer')
        ->assertSuccessful();

    expect($user->fresh()->isAdmin())->toBeFalse();
});

it('accepts the address typed the way a person reads it out', function () {
    /* Accounts are stored lower-cased; people type what they see. */
    $user = User::factory()->create(['email' => 'admin@example.test']);

    $this->artisan('user:admin', ['email' => ' Admin@Example.test '])->assertSuccessful();

    expect($user->fresh()->isAdmin())->toBeTrue();
});

it('says so rather than working twice', function () {
    $user = User::factory()->create(['email' => 'admin@example.test']);
    $user->forceFill(['is_admin' => true])->save();

    $this->artisan('user:admin', ['email' => 'admin@example.test'])
        ->expectsOutputToContain('already an administrator')
        ->assertSuccessful();
});

it('fails when there is no such account', function () {
    $this->artisan('user:admin', ['email' => 'nobody@example.test'])
        ->expectsOutputToContain('No account found')
        ->assertFailed();
});

it('never prints the address back', function () {
    /* It identifies a person, and the command is run at a terminal with a
     * scrollback. The account id is enough to confirm the right row moved —
     * including when the address was wrong, which is exactly when somebody is
     * most likely to be looking over a shoulder. */
    $user = User::factory()->create(['email' => 'admin@example.test']);

    $granted = Artisan::call('user:admin', ['email' => 'admin@example.test']);
    $grantedOutput = Artisan::output();

    Artisan::call('user:admin', ['email' => 'nobody@example.test']);
    $missingOutput = Artisan::output();

    expect($granted)->toBe(0)
        ->and($grantedOutput)->not->toContain('admin@example.test')
        ->and($grantedOutput)->toContain('#'.$user->id)
        ->and($missingOutput)->not->toContain('nobody@example.test');
});

it('does not touch anybody else', function () {
    $target = User::factory()->create(['email' => 'admin@example.test']);
    $other = User::factory()->create(['email' => 'other@example.test']);

    $this->artisan('user:admin', ['email' => 'admin@example.test'])->assertSuccessful();

    expect($target->fresh()->isAdmin())->toBeTrue()
        ->and($other->fresh()->isAdmin())->toBeFalse();
});
