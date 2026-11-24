<?php

/*
 * The landing page "/" is the sign-in page (passwordless: a code by e-mail,
 * Google or Apple), not a marketing splash: it renders the e-mail step inline
 * and hands the OTP step the same Alpine component the overlays use. Because
 * "/" must answer on every deploy it lives OUTSIDE the membership flag group —
 * with membership off there is no auth to offer, so visitors are sent to the
 * guest fitting room.
 */

use App\Models\User;

// This file checks the English copy: the pages are asked for in English
// (the suite's default language is APP_LOCALE in phpunit.xml).
beforeEach(fn () => $this->withHeader('Accept-Language', 'en'));

it('serves the sign-in page to guests while membership is on', function () {
    config(['features.membership' => true]);

    $this->get('/')
        ->assertOk()
        ->assertViewIs('auth.login')
        ->assertSee('Sign In')
        ->assertSee('id="login-email-step"', escape: false)
        ->assertSee('id="login-otp-step"', escape: false)
        ->assertSee('class="email-input"', escape: false)
        ->assertSee('autocomplete="email"', escape: false);
});

it('offers the plans as the only alternative — no guest try-on entry', function () {
    config(['features.membership' => true]);

    $this->get('/')
        ->assertSee('id="login-pricing-link"', escape: false)
        ->assertSee(route('membership.pricing'))
        // The try-on page is internal (FEATURE_FITTING_ROOM); see
        // FittingRoomAccessTest.
        ->assertDontSee('id="login-guest-link"', escape: false);
});

it('offers Google and Apple only when each provider is configured', function () {
    config(['features.membership' => true, 'services.google.client_id' => '', 'services.apple.client_id' => '']);

    $this->get('/')
        ->assertDontSee('id="auth-google"', escape: false)
        ->assertDontSee('id="auth-apple"', escape: false)
        ->assertDontSee('Continue with');

    config(['services.google.client_id' => 'g-client']);

    $this->get('/')
        ->assertSee('id="auth-google"', escape: false)
        ->assertSee(route('auth.social.redirect', 'google'), escape: false)
        ->assertSee('Continue with Google')
        ->assertDontSee('id="auth-apple"', escape: false);

    config(['services.apple.client_id' => 'com.example.signin']);

    $this->get('/')
        ->assertSee('id="auth-apple"', escape: false)
        ->assertSee(route('auth.social.redirect', 'apple'), escape: false)
        ->assertSee('Continue with Apple');
});

it('loads no third-party script for the providers — the buttons are plain links', function () {
    config(['features.membership' => true, 'services.google.client_id' => 'g-client', 'services.apple.client_id' => 'com.example.signin']);

    $this->get('/')
        ->assertDontSee('accounts.google.com', escape: false)
        ->assertDontSee('appleid.cdn-apple.com', escape: false);
});

it('shows a provider sign-in failure back on the page, once', function () {
    config(['features.membership' => true]);

    $this->withSession(['_flash' => ['old' => ['auth_error'], 'new' => []], 'auth_error' => 'Sign-in did not complete. Please try again.'])
        ->get('/')
        ->assertSee('id="auth-error"', escape: false)
        ->assertSee('Sign-in did not complete. Please try again.');
});

it('drops the auth overlays on the sign-in page (the page itself is the auth screen)', function () {
    config(['features.membership' => true]);

    $this->get('/')
        ->assertDontSee('id="email-modal"', escape: false)
        ->assertDontSee('id="otp-modal"', escape: false)
        ->assertDontSee('id="gate-modal"', escape: false);
});

it('keeps the honest photo-retention wording', function () {
    config(['features.membership' => true]);

    $this->get('/')->assertSee('deleted within 72 hours');
});

it('sends a signed-in member straight to the account', function () {
    config(['features.membership' => true]);

    $this->actingAs(User::factory()->emailOnly()->create())
        ->get('/')
        ->assertRedirect(route('membership.account.dashboard'));
});

it('falls back to the try-on page while membership is off but that page is open', function () {
    config(['features.membership' => false, 'features.fitting_room' => true]);

    $this->get('/')->assertRedirect(route('fitting-room'));
});

it('falls back for a stale session too when membership goes off', function () {
    config(['features.membership' => false, 'features.fitting_room' => true]);

    $this->actingAs(User::factory()->emailOnly()->create())
        ->get('/')
        ->assertRedirect(route('fitting-room'));
});

it('404s when neither membership nor the try-on page is open', function () {
    // Nothing public is left to land on — the site has no open surface.
    config(['features.membership' => false, 'features.fitting_room' => false]);

    $this->get('/')->assertNotFound();
});

it('drops the Laravel starter page', function () {
    expect(view()->exists('welcome'))->toBeFalse();
});
