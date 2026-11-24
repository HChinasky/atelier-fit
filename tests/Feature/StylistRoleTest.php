<?php

use App\Models\User;

/*
 * The stylist flag.
 *
 * A second boolean beside is_admin rather than a role column: the two are not
 * exclusive — the one person who has either currently has both — and a `role`
 * column would say that they are. Neither has a path from the interface; both
 * are set from a shell, which is the bar for deploying anyway.
 */

beforeEach(function () {
    config(['features.color_analysis' => true]);
});

it('is off by default', function () {
    expect(User::factory()->create()->isStylist())->toBeFalse();
});

it('counts an administrator as a stylist', function () {
    /* Otherwise an administrator locks themselves out of a screen they can
     * grant themselves access to in one command, which is not a gate. */
    $admin = User::factory()->create(['is_admin' => true]);

    expect($admin->isStylist())->toBeTrue();
    expect((bool) $admin->is_stylist)->toBeFalse();
});

it('grants and revokes the flag by e-mail address', function () {
    $user = User::factory()->create(['email' => 'one@example.test']);

    $this->artisan('user:stylist', ['email' => 'one@example.test'])->assertSuccessful();
    expect($user->refresh()->is_stylist)->toBeTrue();

    $this->artisan('user:stylist', ['email' => 'one@example.test', '--revoke' => true])
        ->assertSuccessful();
    expect($user->refresh()->is_stylist)->toBeFalse();
});

it('finds the account however the address was typed', function () {
    $user = User::factory()->create(['email' => 'two@example.test']);

    $this->artisan('user:stylist', ['email' => ' Two@Example.TEST '])->assertSuccessful();

    expect($user->refresh()->is_stylist)->toBeTrue();
});

it('never echoes the address back', function () {
    /* The command may be run from a shared terminal with a scrollback. The
     * account id is enough to confirm the right row was touched. */
    User::factory()->create(['email' => 'three@example.test']);

    $this->artisan('user:stylist', ['email' => 'three@example.test'])
        ->doesntExpectOutputToContain('three@example.test')
        ->assertSuccessful();
});

it('says nothing about an address it did not find', function () {
    $this->artisan('user:stylist', ['email' => 'nobody@example.test'])
        ->doesntExpectOutputToContain('nobody@example.test')
        ->assertFailed();
});

it('cannot be set by a mass assignment', function () {
    $user = User::factory()->create();

    $user->fill(['is_stylist' => true, 'is_admin' => true])->save();

    expect($user->refresh()->is_stylist)->toBeFalse()
        ->and($user->refresh()->is_admin)->toBeFalse();
});
