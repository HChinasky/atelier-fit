<?php

use App\Mail\LoginCode;
use App\Models\OtpCode;
use App\Models\StyleProfile;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

/*
 * Passwordless e-mail login endpoints: send/verify/logout, the auth gate on
 * /account/*, and the Turnstile check before sends. Mail is faked
 * (Mail::fake); Turnstile's siteverify is Http::fake()d — no real external
 * calls.
 */

const AUTH_EMAIL = 'member@example.test';

beforeEach(function () {
    config(['features.membership' => true]);
    Mail::fake();
});

/** The code inside the last sign-in mail the fake caught. */
function lastMailedCode(): ?string
{
    $mail = Mail::sent(LoginCode::class)->last();

    return $mail?->code;
}

/** Walk the full send→verify flow and return the authenticated response. */
function loginViaCode(string $email = AUTH_EMAIL)
{
    test()->postJson(route('auth.email.send'), [
        'email' => $email,
        'fingerprint' => 'fp-test',
    ])->assertOk();

    return test()->postJson(route('auth.email.verify'), [
        'email' => $email,
        'code' => lastMailedCode(),
    ]);
}

// ---- feature flag ----

it('404s the auth endpoints while the membership flag is off', function () {
    config(['features.membership' => false]);

    $this->postJson(route('auth.email.send'), ['email' => AUTH_EMAIL, 'fingerprint' => 'fp'])->assertNotFound();
    $this->postJson(route('auth.email.verify'), ['email' => AUTH_EMAIL, 'code' => '123456'])->assertNotFound();
    $this->post(route('auth.logout'))->assertNotFound();
});

// ---- send ----

it('mails a code to the address, normalised', function () {
    $this->postJson(route('auth.email.send'), [
        'email' => '  Member@Example.TEST ',
        'fingerprint' => 'fp-test',
    ])->assertOk()->assertJson(['status' => 'sent', 'resend_in' => 60]);

    Mail::assertSent(LoginCode::class, fn (LoginCode $mail) => $mail->hasTo(AUTH_EMAIL));
    expect(OtpCode::where('email', AUTH_EMAIL)->exists())->toBeTrue();
});

it('puts the code in the subject line and the body, with the TTL', function () {
    config(['otp.ttl_minutes' => 10]);

    $this->postJson(route('auth.email.send'), ['email' => AUTH_EMAIL, 'fingerprint' => 'fp-test'])->assertOk();

    $mail = Mail::sent(LoginCode::class)->sole();
    $code = $mail->code;

    expect($code)->toMatch('/^\d{6}$/')
        ->and($mail->envelope()->subject)->toContain($code);

    $rendered = $mail->render();
    expect($rendered)->toContain($code)->toContain('Код дійсний 10 хв.');
});

it('rejects an address that is not one, with a validation error', function (string $email) {
    $this->postJson(route('auth.email.send'), [
        'email' => $email,
        'fingerprint' => 'fp-test',
    ])->assertStatus(422)->assertJsonValidationErrors('email');

    Mail::assertNothingSent();
})->with(['hello', 'not an email', 'a@b', '@example.test', '+971501234567']);

it('requires a fingerprint', function () {
    $this->postJson(route('auth.email.send'), ['email' => AUTH_EMAIL])
        ->assertStatus(422)->assertJsonValidationErrors('fingerprint');
});

it('returns 429 with retry_after when the requester is throttled', function () {
    $payload = ['email' => AUTH_EMAIL, 'fingerprint' => 'fp-test'];

    $this->postJson(route('auth.email.send'), $payload)->assertOk();
    $this->postJson(route('auth.email.send'), $payload)
        ->assertStatus(429)
        ->assertJson(['status' => 'rate_limited'])
        ->assertJsonStructure(['retry_after']);
});

it('reports a mail the transport refused as a 500 with a message — never a 502/504', function () {
    // Cloudflare replaces an origin 502/504 with its own error page, so the
    // JSON body — and the honest "could not send" message in it — would never
    // reach the browser, and the UI would fall back to a generic error.
    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('smtp down'));

    $response = $this->postJson(route('auth.email.send'), [
        'email' => AUTH_EMAIL,
        'fingerprint' => 'fp-test',
    ]);

    expect($response->status())->toBe(500)
        ->and($response->status())->not->toBeIn([502, 504])
        ->and(OtpCode::count())->toBe(0);

    $response->assertJson(['status' => 'failed'])
        ->assertJsonPath('message', 'Не вдалося надіслати код. Перевір адресу й спробуй ще раз.');
});

// ---- verify & login ----

it('logs in and auto-creates the user on first verification', function () {
    $response = loginViaCode();

    // A new account lands on the style quiz, not the dashboard.
    $response->assertOk()->assertJson([
        'status' => 'ok',
        'redirect' => route('membership.account.onboarding'),
    ]);

    $this->assertAuthenticated();

    $user = User::sole();
    expect($user->email)->toBe(AUTH_EMAIL)
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->name)->toBeNull()
        ->and($user->password)->toBeNull();
});

it('treats the address case-insensitively on verify too', function () {
    $this->postJson(route('auth.email.send'), ['email' => AUTH_EMAIL, 'fingerprint' => 'fp-test'])->assertOk();

    $this->postJson(route('auth.email.verify'), ['email' => 'MEMBER@example.test', 'code' => lastMailedCode()])
        ->assertOk()->assertJsonPath('status', 'ok');

    expect(User::sole()->email)->toBe(AUTH_EMAIL);
});

it('reuses the existing account on later logins and keeps the first verification stamp', function () {
    $existing = User::factory()->emailOnly()->create(['email' => AUTH_EMAIL]);
    $stamp = $existing->email_verified_at;

    $this->travel(1)->hour();

    loginViaCode()->assertOk();

    $this->assertAuthenticatedAs($existing->fresh());
    expect(User::count())->toBe(1)
        ->and($existing->fresh()->email_verified_at->equalTo($stamp))->toBeTrue();
});

it('lands on the dashboard once the style quiz has been answered or skipped', function (string $state) {
    $member = User::factory()->emailOnly()->create(['email' => AUTH_EMAIL]);
    StyleProfile::factory()->{$state}()->for($member)->create();

    loginViaCode()->assertOk()->assertJsonPath('redirect', route('membership.account.dashboard'));
})->with(['completed', 'skipped']);

it('still offers the quiz to an existing member who has never been through it', function () {
    User::factory()->emailOnly()->create(['email' => AUTH_EMAIL]);

    loginViaCode()->assertOk()->assertJsonPath('redirect', route('membership.account.onboarding'));
});

it('rejects a wrong code with the attempts left and stays guest', function () {
    $this->postJson(route('auth.email.send'), ['email' => AUTH_EMAIL, 'fingerprint' => 'fp-test'])->assertOk();

    $this->postJson(route('auth.email.verify'), ['email' => AUTH_EMAIL, 'code' => '000000'])
        ->assertStatus(422)
        ->assertJson(['status' => 'invalid', 'attempts_left' => 1]);

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('blocks the address after two wrong entries — even the correct code is refused', function () {
    $this->postJson(route('auth.email.send'), ['email' => AUTH_EMAIL, 'fingerprint' => 'fp-test'])->assertOk();
    $code = lastMailedCode();

    $this->postJson(route('auth.email.verify'), ['email' => AUTH_EMAIL, 'code' => '000000'])->assertStatus(422);
    $this->postJson(route('auth.email.verify'), ['email' => AUTH_EMAIL, 'code' => '000000'])
        ->assertStatus(429)
        ->assertJson(['status' => 'blocked'])
        ->assertJsonStructure(['retry_after']);

    $this->postJson(route('auth.email.verify'), ['email' => AUTH_EMAIL, 'code' => $code])
        ->assertStatus(429)
        ->assertJson(['status' => 'blocked']);

    $this->assertGuest();
});

it('reports an expired code distinctly so the UI can offer a resend', function () {
    $this->postJson(route('auth.email.send'), ['email' => AUTH_EMAIL, 'fingerprint' => 'fp-test'])->assertOk();
    $code = lastMailedCode();

    $this->travel(11)->minutes();

    $this->postJson(route('auth.email.verify'), ['email' => AUTH_EMAIL, 'code' => $code])
        ->assertStatus(422)
        ->assertJson(['status' => 'expired']);

    $this->assertGuest();
});

it('validates the code shape before touching the OTP core', function () {
    $this->postJson(route('auth.email.verify'), ['email' => AUTH_EMAIL, 'code' => '12345'])
        ->assertStatus(422)->assertJsonValidationErrors('code');
});

// ---- logout ----

it('logs out and lands on the public pricing page', function () {
    $user = User::factory()->emailOnly()->create();

    $this->actingAs($user)
        ->post(route('auth.logout'))
        ->assertRedirect(route('membership.pricing'));

    $this->assertGuest();
});

// ---- auth gate on /account/* ----

it('sends guests from every account page to the sign-in page', function (string $route) {
    $this->get($route)->assertRedirect(route('login'));
})->with(['/account', '/account/looks', '/account/wardrobe', '/account/capsules', '/account/vip', '/account/settings']);

it('serves account pages to an authenticated member', function () {
    $this->actingAs(User::factory()->emailOnly()->onboarded()->create())
        ->get('/account')
        ->assertOk();
});

it('keeps pricing public for guests', function () {
    $this->get('/pricing')->assertOk();
});

// ---- Turnstile gate before sends ----

it('refuses to send when Turnstile rejects the token', function () {
    config(['services.turnstile.secret' => 'ts-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => false])]);

    $this->postJson(route('auth.email.send'), [
        'email' => AUTH_EMAIL,
        'fingerprint' => 'fp-test',
        'turnstile_token' => 'bad-token',
    ])->assertStatus(403)->assertJson(['status' => 'turnstile_failed']);

    Mail::assertNothingSent();
    expect(OtpCode::count())->toBe(0);
});

it('refuses to send when no Turnstile token is supplied while the check is on', function () {
    config(['services.turnstile.secret' => 'ts-secret']);
    Http::fake();

    $this->postJson(route('auth.email.send'), [
        'email' => AUTH_EMAIL,
        'fingerprint' => 'fp-test',
    ])->assertStatus(403)->assertJson(['status' => 'turnstile_failed']);

    Http::assertNothingSent(); // no token → refused locally, no siteverify call
    Mail::assertNothingSent();
});

it('sends when Turnstile confirms the token', function () {
    config(['services.turnstile.secret' => 'ts-secret']);
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);

    $this->postJson(route('auth.email.send'), [
        'email' => AUTH_EMAIL,
        'fingerprint' => 'fp-test',
        'turnstile_token' => 'good-token',
    ])->assertOk()->assertJson(['status' => 'sent']);

    Mail::assertSent(LoginCode::class, 1);

    Http::assertSent(function ($request) {
        return str_contains($request->url(), 'challenges.cloudflare.com')
            && $request['secret'] === 'ts-secret'
            && $request['response'] === 'good-token';
    });
});
