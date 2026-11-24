<?php

use App\Http\Middleware\TrackAttribution;
use App\Mail\LoginCode;
use App\Models\Lead;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Waitlist\WaitlistSignup;
use Illuminate\Support\Facades\Mail;

// This file checks the English copy: the pages are asked for in English
// (the suite's default language is APP_LOCALE in phpunit.xml).
beforeEach(fn () => $this->withHeader('Accept-Language', 'en'));

/*
 * The pre-launch curtain (FEATURE_COMING_SOON). With it up, the sign-in field
 * keeps the address as a waiting-list lead and sends nobody a code; the
 * visitor lands on /coming-soon and is told both. Test addresses still get a
 * code, so the owner can reach the account. With it down, nothing here exists.
 */

const SOON_EMAIL = 'early@example.test';

beforeEach(function () {
    config(['features.membership' => true, 'features.coming_soon' => true]);
    Mail::fake();
});

function leaveAddress(string $email = SOON_EMAIL)
{
    return test()->postJson(route('auth.email.send'), ['email' => $email, 'fingerprint' => 'fp-soon']);
}

// --- the flag ----------------------------------------------------------------

it('is off out of the box', function () {
    expect((bool) env('FEATURE_COMING_SOON', false))->toBeFalse();
});

it('does not exist while the flag is off: codes go out as usual and the page is a 404', function () {
    config(['features.coming_soon' => false]);

    leaveAddress()->assertOk()->assertJsonPath('status', 'sent');

    Mail::assertSent(LoginCode::class, 1);
    expect(Lead::count())->toBe(0);
    $this->get('/coming-soon')->assertNotFound();
});

// --- leaving an address --------------------------------------------------------

it('keeps the address as a waiting-list lead and sends no code', function () {
    leaveAddress()
        ->assertOk()
        ->assertJsonPath('status', 'waitlisted')
        ->assertJsonPath('redirect', route('coming-soon'));

    $lead = Lead::sole();
    expect($lead->channel)->toBe('email')
        ->and($lead->contact)->toBe(SOON_EMAIL)
        ->and($lead->tags)->toBe(['waitlist'])
        ->and($lead->ip)->not->toBeNull()
        ->and($lead->user_id)->toBeNull()
        ->and(OtpCode::count())->toBe(0)
        ->and(User::count())->toBe(0);
    Mail::assertNothingSent();
});

it('keeps one row per address, refreshed rather than doubled', function () {
    leaveAddress()->assertOk();
    $first = Lead::sole();
    $this->travel(2)->days();
    leaveAddress('Early@Example.test')->assertOk();

    expect(Lead::count())->toBe(1)
        ->and(Lead::sole()->updated_at->isAfter($first->updated_at))->toBeTrue();
});

it('records where the visitor came from, like any other lead', function () {
    $this->withSession([TrackAttribution::SESSION_FIRST => [
        'utm_source' => 'instagram',
        'utm_campaign' => 'prelaunch',
        'landing_path' => '/',
        'referrer_host' => 'instagram.com',
    ]]);

    leaveAddress()->assertOk();

    $lead = Lead::sole();
    expect($lead->utm_source)->toBe('instagram')
        ->and($lead->utm_campaign)->toBe('prelaunch')
        ->and($lead->referrer_host)->toBe('instagram.com');
});

it('still validates the address: nonsense is refused, not kept', function () {
    $this->postJson(route('auth.email.send'), ['email' => '+97143334444', 'fingerprint' => 'fp'])
        ->assertUnprocessable();
    $this->postJson(route('auth.email.send'), ['email' => 'hello', 'fingerprint' => 'fp'])
        ->assertUnprocessable();

    expect(Lead::count())->toBe(0);
});

it('lets a test address through to a real code, so the owner can get in behind the curtain', function () {
    config(['otp.test_addresses' => ['owner@example.test'], 'otp.test_code' => '123456']);

    leaveAddress('owner@example.test')->assertOk()->assertJsonPath('status', 'sent');

    expect(Lead::count())->toBe(0);
});

// --- the page ----------------------------------------------------------------

it('shows the kept address back, masked, after leaving it', function () {
    leaveAddress()->assertOk();

    config(['membership.home_url' => 'https://atelierfit.test']);

    $this->get('/coming-soon')
        ->assertOk()
        ->assertViewIs('auth.coming-soon')
        ->assertSee('id="coming-soon"', escape: false)
        ->assertSee('Coming soon')
        ->assertSee('Your fitting room is almost ready.')
        ->assertSee("You're now on the waitlist. We'll e-mail you as soon as Atelier Fit AI Fitting Room launches.")
        ->assertSee('id="waitlist-email"', escape: false)
        ->assertSee('e••••@example.test')
        ->assertDontSee('early@example.test')
        // The way out is the main site, from config — never a hardcoded host.
        ->assertSee('href="https://atelierfit.test"', escape: false)
        ->assertSee('Back to Atelier Fit')
        ->assertSee(route('membership.pricing'));
});

it('makes sense opened cold, and points back to the field', function () {
    $this->get('/coming-soon')
        ->assertOk()
        ->assertSee('Your fitting room is almost ready.')
        ->assertDontSee('id="waitlist-email"', escape: false)
        ->assertSee('id="waitlist-back"', escape: false)
        ->assertSee(route('login'));
});

it('words the field as a sign-in whether or not the curtain is up', function (bool $curtain) {
    config(['features.coming_soon' => $curtain]);

    $this->get('/')
        ->assertOk()
        ->assertSee('Sign in')
        ->assertSee('Welcome Back')
        ->assertSee('Enter your e-mail to receive a one-time sign-in code, or continue with Google or Apple.')
        ->assertSee('Send Code')
        ->assertSee('We sent a 6-digit sign-in code to')
        ->assertSee('Verify &amp; Sign In', escape: false)
        ->assertDontSee('Be first in')
        ->assertDontSee('Notify me');
})->with([true, false]);

it('masks all but the first letter and the domain', function () {
    expect(WaitlistSignup::mask('early@example.test'))->toBe('e••••@example.test')
        ->and(WaitlistSignup::mask('jo@gmail.com'))->toBe('j••@gmail.com')
        ->and(WaitlistSignup::mask('nonsense'))->toBe('nonsense');
});
