<?php

use App\Models\SocialAccount;
use App\Models\StyleProfile;
use App\Models\User;
use App\Models\WelcomeGrant;
use App\Services\Credits\CreditService;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
 * Sign in with Google / Apple. Socialite is mocked at the facade — the
 * provider round trip never happens — and what is pinned is ours: which
 * account a provider identity lands on, the welcome grant, the once-only
 * link, and the two refusals (provider off, provider gave no address).
 */

beforeEach(function () {
    config([
        'features.membership' => true,
        'services.google.client_id' => 'g-client',
        'services.google.client_secret' => 'g-secret',
        'services.apple.client_id' => 'com.example.signin',
    ]);
});

/** What Socialite hands back after the callback, for a given provider. */
function providerReturns(string $provider, ?string $id, ?string $email, ?string $name = null): void
{
    $identity = (new SocialiteUser)->map(['id' => $id, 'email' => $email, 'name' => $name]);

    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('stateless')->andReturnSelf();
    $driver->shouldReceive('cookieNonce')->andReturnSelf();
    $driver->shouldReceive('user')->andReturn($identity);

    Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
}

function providerFails(string $provider): void
{
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('stateless')->andReturnSelf();
    $driver->shouldReceive('cookieNonce')->andReturnSelf();
    $driver->shouldReceive('user')->andThrow(new InvalidStateException);

    Socialite::shouldReceive('driver')->with($provider)->andReturn($driver);
}

// ---- redirect ----

it('sends the visitor to the provider', function (string $provider) {
    $driver = Mockery::mock(Provider::class);
    $driver->shouldReceive('stateless')->andReturnSelf();
    $driver->shouldReceive('cookieNonce')->andReturnSelf();
    $driver->shouldReceive('redirect')->once()->andReturn(redirect()->away('https://provider.test/authorize'));
    Socialite::shouldReceive('driver')->with($provider)->once()->andReturn($driver);

    $this->get(route('auth.social.redirect', $provider))
        ->assertRedirect('https://provider.test/authorize');
})->with(['google', 'apple']);

it('runs Apple stateless with the nonce in a cookie, Google with the session state', function () {
    $apple = Mockery::mock(Provider::class);
    $apple->shouldReceive('stateless')->once()->andReturnSelf();
    $apple->shouldReceive('cookieNonce')->once()->andReturnSelf();
    $apple->shouldReceive('redirect')->andReturn(redirect()->away('https://appleid.apple.com/auth'));

    $google = Mockery::mock(Provider::class);
    $google->shouldNotReceive('stateless');
    $google->shouldReceive('redirect')->andReturn(redirect()->away('https://accounts.google.com/o/oauth2'));

    Socialite::shouldReceive('driver')->with('apple')->andReturn($apple);
    Socialite::shouldReceive('driver')->with('google')->andReturn($google);

    $this->get(route('auth.social.redirect', 'apple'))->assertRedirect();
    $this->get(route('auth.social.redirect', 'google'))->assertRedirect();
});

it('404s a provider that is not configured', function () {
    config(['services.apple.client_id' => '']);

    $this->get(route('auth.social.redirect', 'apple'))->assertNotFound();
    $this->get(route('auth.social.callback', 'apple'))->assertNotFound();
});

it('404s an unknown provider', function () {
    $this->get('/auth/facebook/redirect')->assertNotFound();
});

it('404s everything while the membership flag is off', function () {
    config(['features.membership' => false]);

    $this->get(route('auth.social.redirect', 'google'))->assertNotFound();
    $this->get(route('auth.social.callback', 'google'))->assertNotFound();
});

// ---- callback: first sign-in ----

it('creates the account from the provider e-mail, links the identity, grants the welcome credits', function () {
    providerReturns('google', 'g-123', 'Jane@Example.test', 'Jane Doe');

    $this->get(route('auth.social.callback', 'google'))
        ->assertRedirect(route('membership.account.onboarding'));

    $this->assertAuthenticated();

    $user = User::sole();
    expect($user->email)->toBe('jane@example.test')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->name)->toBe('Jane Doe')
        ->and($user->password)->toBeNull()
        ->and(app(CreditService::class)->balance($user))->toBe((int) config('membership.welcome_credits'));

    $link = SocialAccount::sole();
    expect($link->user_id)->toBe($user->id)
        ->and($link->provider)->toBe('google')
        ->and($link->provider_id)->toBe('g-123')
        ->and($link->email)->toBe('jane@example.test');
});

it('accepts the POST Apple answers with, without a CSRF token', function () {
    providerReturns('apple', '001234.abcdef.5678', 'relay@privaterelay.appleid.com', 'Jane');

    $this->post(route('auth.social.callback', 'apple'), ['code' => 'c', 'state' => 's', 'id_token' => 't'])
        ->assertRedirect(route('membership.account.onboarding'));

    $this->assertAuthenticated();
    expect(SocialAccount::sole()->provider)->toBe('apple');
});

it('lands on the dashboard once the quiz was offered', function () {
    $member = User::factory()->emailOnly()->create(['email' => 'jane@example.test']);
    StyleProfile::factory()->skipped()->for($member)->create();
    providerReturns('google', 'g-123', 'jane@example.test');

    $this->get(route('auth.social.callback', 'google'))
        ->assertRedirect(route('membership.account.dashboard'));
});

// ---- callback: attaching to what exists ----

it('attaches to the existing account with that e-mail instead of making a second one', function () {
    $existing = User::factory()->emailOnly()->create(['email' => 'jane@example.test']);
    providerReturns('google', 'g-123', 'JANE@example.test');

    $this->get(route('auth.social.callback', 'google'))->assertRedirect();

    $this->assertAuthenticatedAs($existing->fresh());
    expect(User::count())->toBe(1)
        ->and(SocialAccount::sole()->user_id)->toBe($existing->id);
});

it('finds the account by the provider identity even when the provider e-mail has changed', function () {
    $link = SocialAccount::factory()->create(['provider' => 'google', 'provider_id' => 'g-123', 'email' => 'old@example.test']);
    providerReturns('google', 'g-123', 'new@example.test');

    $this->get(route('auth.social.callback', 'google'))->assertRedirect();

    $this->assertAuthenticatedAs($link->user->fresh());
    expect(User::count())->toBe(1)
        ->and(SocialAccount::count())->toBe(1)
        ->and($link->user->fresh()->email)->toBe($link->user->email);
});

it('lets one account sign in through both providers and the e-mail code', function () {
    providerReturns('google', 'g-123', 'jane@example.test');
    $this->get(route('auth.social.callback', 'google'))->assertRedirect();
    auth()->logout();

    Mockery::close();
    providerReturns('apple', 'a-999', 'jane@example.test');
    $this->get(route('auth.social.callback', 'apple'))->assertRedirect();

    expect(User::count())->toBe(1)
        ->and(SocialAccount::count())->toBe(2)
        ->and(SocialAccount::pluck('provider')->sort()->values()->all())->toBe(['apple', 'google']);
});

it('grants the welcome credits once per address across every way in', function () {
    providerReturns('google', 'g-123', 'jane@example.test');
    $this->get(route('auth.social.callback', 'google'))->assertRedirect();
    $user = User::sole();

    auth()->logout();
    Mockery::close();
    providerReturns('apple', 'a-999', 'jane@example.test');
    $this->get(route('auth.social.callback', 'apple'))->assertRedirect();

    expect(app(CreditService::class)->balance($user))->toBe((int) config('membership.welcome_credits'))
        ->and(WelcomeGrant::count())->toBe(1);
});

// ---- callback: refusals ----

it('refuses an identity without an e-mail address, with a message and no account', function () {
    providerReturns('apple', 'a-999', null);

    $this->get(route('auth.social.callback', 'apple'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('auth_error', fn (string $m) => str_contains($m, 'Apple'));

    $this->assertGuest();
    expect(User::count())->toBe(0)
        ->and(SocialAccount::count())->toBe(0);
});

it('sends a cancelled or failed provider round trip back to the sign-in page', function () {
    $this->get(route('auth.social.callback', 'google', false).'?error=access_denied')
        ->assertRedirect(route('login'))
        ->assertSessionMissing('auth_error');

    providerFails('google');

    $this->get(route('auth.social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('auth_error');

    $this->assertGuest();
});
