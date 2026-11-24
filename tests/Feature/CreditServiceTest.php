<?php

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WelcomeGrant;
use App\Services\Auth\MemberAuthenticator;
use App\Services\Credits\CreditService;

/*
 * The member credit ledger (Stage B3): welcome grant (+4, once per E-MAIL
 * ADDRESS ever — surviving account deletion), spend, and idempotent refunds.
 */

const CREDIT_EMAIL = 'member@example.test';

function credits(): CreditService
{
    return app(CreditService::class);
}

// ---- welcome grant ----

it('grants the configured welcome credits on first grant for an address', function () {
    config(['membership.welcome_credits' => 4]);
    $user = User::factory()->emailOnly()->create(['email' => CREDIT_EMAIL]);

    expect(credits()->grantWelcome($user, CREDIT_EMAIL))->toBeTrue()
        ->and(credits()->balance($user))->toBe(4)
        ->and(WelcomeGrant::count())->toBe(1)
        ->and(CreditTransaction::sole()->reason)->toBe(CreditTransaction::REASON_WELCOME);
});

it('never stores the raw address in the grant marker', function () {
    $user = User::factory()->emailOnly()->create(['email' => CREDIT_EMAIL]);

    credits()->grantWelcome($user, CREDIT_EMAIL);

    $hash = WelcomeGrant::sole()->identity_hash;
    expect($hash)->not->toContain('member')
        ->and($hash)->not->toContain('@')
        ->and($hash)->toBe(credits()->identityHash(CREDIT_EMAIL))
        ->and(credits()->identityHash(CREDIT_EMAIL))->not->toBe(credits()->identityHash('other@example.test'));
});

it('hashes the address in normalised form, so spelling never splits a grant', function () {
    expect(credits()->identityHash(' Member@Example.TEST '))->toBe(credits()->identityHash(CREDIT_EMAIL));
});

it('refuses a second grant for the same address', function () {
    $user = User::factory()->emailOnly()->create(['email' => CREDIT_EMAIL]);

    expect(credits()->grantWelcome($user, CREDIT_EMAIL))->toBeTrue()
        ->and(credits()->grantWelcome($user, 'MEMBER@example.test'))->toBeFalse()
        ->and(credits()->balance($user))->toBe(4)
        ->and(CreditTransaction::count())->toBe(1);
});

it('does NOT re-grant when the account is deleted and the same address signs up again', function () {
    // The full production path: first login grants, delete account, re-register
    // the same address.
    $authenticator = app(MemberAuthenticator::class);

    $first = $authenticator->login(CREDIT_EMAIL);
    expect(credits()->balance($first))->toBe(4);

    $first->delete(); // ledger cascades away; welcome_grants must survive
    expect(WelcomeGrant::count())->toBe(1);

    $second = $authenticator->login(CREDIT_EMAIL);

    expect($second->id)->not->toBe($first->id)
        ->and($second->email_verified_at)->not->toBeNull() // logged in fine
        ->and(credits()->balance($second))->toBe(0)        // but no second +4
        ->and(WelcomeGrant::count())->toBe(1);
});

it('grants welcome credits through the login path exactly once across logins', function () {
    $authenticator = app(MemberAuthenticator::class);

    $user = $authenticator->login(CREDIT_EMAIL);
    $again = $authenticator->login(CREDIT_EMAIL); // same, still-alive account

    expect($again->id)->toBe($user->id)
        ->and(credits()->balance($user))->toBe(4)
        ->and(CreditTransaction::count())->toBe(1);
});

it('keeps the name a provider sent only when the account has none', function () {
    $authenticator = app(MemberAuthenticator::class);

    $user = $authenticator->login(CREDIT_EMAIL, 'Jane Doe');
    expect($user->name)->toBe('Jane Doe');

    $authenticator->login(CREDIT_EMAIL, 'Somebody Else');
    expect($user->fresh()->name)->toBe('Jane Doe');
});

// ---- balance & spend ----

it('sums the ledger as the balance', function () {
    $user = User::factory()->emailOnly()->create();
    CreditTransaction::factory()->for($user)->create(['delta' => 4]);
    CreditTransaction::factory()->spend()->for($user)->create();

    expect(credits()->balance($user))->toBe(3);
});

it('spends when the balance covers it and refuses when it does not', function () {
    $user = User::factory()->emailOnly()->create();
    CreditTransaction::factory()->for($user)->create(['delta' => 2]);

    $spend = credits()->spend($user, 2);

    expect($spend)->not->toBeNull()
        ->and($spend->delta)->toBe(-2)
        ->and(credits()->balance($user))->toBe(0)
        // Empty ledger now — the next spend must refuse and change nothing.
        ->and(credits()->spend($user, 1))->toBeNull()
        ->and(credits()->balance($user))->toBe(0);
});

// ---- refunds ----

it('refunds a failed generation once — repeats and strangers are no-ops', function () {
    $user = User::factory()->emailOnly()->create();
    CreditTransaction::factory()->for($user)->create(['delta' => 4]);
    $generation = Generation::factory()->create();

    $spend = credits()->spend($user, 1);
    credits()->attachGeneration($spend, $generation);
    expect(credits()->balance($user))->toBe(3);

    expect(credits()->refundForGeneration($generation))->toBeTrue()
        ->and(credits()->balance($user))->toBe(4)
        // Second refund of the same generation: blocked by the unique index.
        ->and(credits()->refundForGeneration($generation))->toBeFalse()
        ->and(credits()->balance($user))->toBe(4);

    // A generation nobody paid for (guest) refunds nothing.
    expect(credits()->refundForGeneration(Generation::factory()->create()))->toBeFalse();
});
