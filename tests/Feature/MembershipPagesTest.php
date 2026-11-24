<?php

/*
 * Membership & Account — page smoke tests. Screens render from mock plan data
 * (billing lands later), but SESSIONS are real since Stage B2: /account/*
 * requires a logged-in member (guests bounce to the sign-in page "/" — see
 * OtpAuthTest and LoginPageTest), while /pricing stays public. The WHOLE area lives behind
 * FEATURE_MEMBERSHIP: with the flag off each route must be a plain 404.
 */

use App\Models\CreditTransaction;
use App\Models\User;

// This file checks the English copy: the pages are asked for in English
// (the suite's default language is APP_LOCALE in phpunit.xml).
beforeEach(fn () => $this->withHeader('Accept-Language', 'en'));

/** A signed-in member, the way phone signup creates them. */
function actingAsMember()
{
    return test()->actingAs(User::factory()->emailOnly()->onboarded()->create());
}

$membershipRoutes = [
    '/pricing',
    '/account',
    '/account/looks',
    '/account/wardrobe',
    '/account/capsules',
    '/account/vip',
    '/account/settings',
];

it('is disabled by default (flag off out of the box)', function () {
    expect(config('features.membership'))->toBeFalse();
});

it('404s every membership route while the flag is off', function (string $route) {
    config(['features.membership' => false]);

    $this->get($route)->assertNotFound();
})->with($membershipRoutes);

it('serves every membership route to a member while the flag is on', function (string $route) {
    config(['features.membership' => true]);

    actingAsMember()->get($route)->assertOk();
})->with($membershipRoutes);

it('leaves the try-on page to its own flag, untouched by this one', function () {
    // /ai-fitting-room answers to FEATURE_FITTING_ROOM alone (see
    // FittingRoomAccessTest); the membership flag must not move it either way.
    config(['features.fitting_room' => true]);

    config(['features.membership' => false]);
    $this->get('/ai-fitting-room')->assertOk()->assertViewIs('ai-fitting-room');

    config(['features.membership' => true]);
    $this->get('/ai-fitting-room')->assertOk()->assertViewIs('ai-fitting-room');
});

/*
 * --- /pricing ---
 */

it('shows the four tiers with prices and badge, marked as not yet purchasable', function () {
    config(['features.membership' => true]);

    $this->get('/pricing')
        ->assertOk()
        ->assertSeeInOrder(['Fashionista', 'Shopaholic', 'Couture', 'Diva VIP'])
        ->assertSee('AED 39')
        ->assertSee('AED 99')
        ->assertSee('AED 269')
        ->assertSee('AED 749')
        ->assertSee('Most Popular')
        ->assertSee('300 AI try-ons per month')
        // Prices shown without this read as an offer, and no payment provider is
        // connected — so the page itself has to say so, not only the Terms.
        ->assertSee('not available for purchase')
        ->assertSee('Payments are not live yet · Visa / Mastercard / Apple Pay planned · Cancel anytime');
});

it('offers no live-looking way to subscribe, and never draws own card fields', function () {
    config(['features.membership' => true]);

    $page = $this->get('/pricing')
        // The tier buttons are inert until payments exist: a "Choose plan" button
        // opening a payment sheet that takes no money is the misleading part.
        ->assertSee('Coming soon')
        ->assertDontSee("openModal('payment')", escape: false);

    // No self-made card inputs anywhere on the page.
    foreach (['card-number', 'cardnumber', 'cvc', 'cvv', 'expiry'] as $forbidden) {
        $page->assertDontSee($forbidden, escape: false);
    }
});

/*
 * --- /account shell ---
 */

it('renders the account shell with side navigation and mobile tabs', function () {
    config(['features.membership' => true]);

    actingAsMember()->get('/account')
        ->assertOk()
        ->assertSee('id="account-sidebar"', escape: false)
        ->assertSee('id="account-tabs"', escape: false)
        ->assertSee('id="plan-switcher"', escape: false);
});

it('draws the phone\'s tabs from the Figma icons, Colours while the engine is on', function () {
    config(['features.membership' => true, 'features.color_analysis' => true]);

    $page = actingAsMember()->get('/account?plan=vip')->assertOk();
    $tabs = (string) str($page->getContent())->after('id="account-tabs"')->before('</nav>');

    // Home, Colours, Wardrobe, Capsules, Profile — Figma 1872:8303 without Looks, with Colours.
    expect(substr_count($tabs, 'class="tab-icon"'))->toBe(5);
    foreach (['nav-home.svg', 'nav-colours.svg', 'nav-wardrobe.svg', 'nav-capsules.svg', 'nav-profile.svg'] as $icon) {
        expect($tabs)->toContain('images/lk-mob/'.$icon);
    }
    expect($tabs)->toContain('Colours')
        ->and($tabs)->toContain(route('membership.account.color', ['plan' => 'vip']))
        ->and($tabs)->not->toContain('nav-looks.svg')
        ->and($tabs)->not->toContain('Stylist')
        ->and($tabs)->toContain('aria-current="page"');
    expect(public_path('images/lk-mob/nav-colours.svg'))->toBeFile();
    // 25px icons, the owner's call.
    expect(file_get_contents(resource_path('views/membership/layout.blade.php')))
        ->toContain('.acct-tabs .tab-icon { width: 25px; height: 25px;');

    // The engine off: the colour routes 404, so no tab points at them.
    config(['features.color_analysis' => false]);
    $tabs = (string) str(actingAsMember()->get('/account?plan=vip')->getContent())->after('id="account-tabs"')->before('</nav>');
    expect(substr_count($tabs, 'class="tab-icon"'))->toBe(4)
        ->and($tabs)->not->toContain('nav-colours.svg');
});

it('links looks and the stylist from the profile on a phone', function () {
    config(['features.membership' => true, 'features.color_analysis' => true]);
    // The Ukrainian copy of this page is the point here.
    $this->withHeader('Accept-Language', 'uk');

    $more = (string) str(actingAsMember()->get('/account/settings?plan=vip')->assertOk()->getContent())
        ->after('id="settings-more"')->before('</nav>');
    expect($more)->toContain('Мої образи')
        ->and($more)->toContain('Стиліст')
        ->and($more)->toContain(route('membership.account.looks', ['plan' => 'vip']))
        // Colours has its own tab now.
        ->and($more)->not->toContain(route('membership.account.color', ['plan' => 'vip']));

    // Without VIP only looks is left there.
    $more = (string) str(actingAsMember()->get('/account/settings?plan=shopaholic')->assertOk()->getContent())
        ->after('id="settings-more"')->before('</nav>');
    expect($more)->toContain('Мої образи')
        ->and($more)->not->toContain('Стиліст');
});

it('carries the wordmark on one line in the phone top bar; the profile is in the tabs', function () {
    config(['features.membership' => true]);

    actingAsMember()->get('/account')
        ->assertSeeInOrder([
            'id="account-topbar"', 'class="acct-wordmark"',
            '<span class="wm-name">Atelier Fit</span>', '<span class="wm-sep" aria-hidden="true"></span>', '<span class="wm-by">by Atelier Fit</span>',
            '</header>', 'id="account-tabs"', route('membership.account.settings'),
        ], escape: false)
        ->assertDontSee('class="acct-me"', escape: false);

    $layout = file_get_contents(resource_path('views/membership/layout.blade.php'));
    expect($layout)->toContain('.acct-wordmark { display: flex; align-items: baseline; justify-content: center; gap: 9px; white-space: nowrap;')
        ->and($layout)->toContain(".acct-wordmark .wm-name { font-family: 'Prata', serif; font-size: 17px; line-height: 1;")
        ->and($layout)->toContain('letter-spacing: .24em; text-transform: uppercase; color: var(--muted); }')
        // The bar takes a line of its own; with the shell's 100vh minimum a short page must not stretch it.
        ->and($layout)->toContain('.acct-shell { flex-wrap: wrap; align-content: flex-start; }');
});

it('shows the REAL ledger balance in the top bar chip only — no sidebar chip, mock ring, total or renew date', function () {
    config(['features.membership' => true]);

    $user = User::factory()->emailOnly()->onboarded()->create();
    CreditTransaction::factory()->for($user)->create(['delta' => 4]);

    $this->actingAs($user)->get('/account')
        ->assertSeeInOrder(['id="account-topline"', '<span id="token-balance">4</span> credits'], escape: false)
        ->assertDontSee('token-balance-chip')
        // The mock "34 of 50" meter must never reach a real member.
        ->assertDontSee('of 50')
        ->assertDontSee('Renews');
});

it('carries the balance chip on every account screen, not only the dashboard', function () {
    config(['features.membership' => true]);

    $user = User::factory()->emailOnly()->onboarded()->create();
    CreditTransaction::factory()->for($user)->create(['delta' => 7]);

    $this->actingAs($user)->get('/account/looks')
        ->assertSee('<span id="token-balance">7</span> credits', escape: false);
});

it('speaks singular for a single remaining credit', function () {
    config(['features.membership' => true]);

    $user = User::factory()->emailOnly()->onboarded()->create();
    CreditTransaction::factory()->for($user)->create(['delta' => 1]);

    $this->actingAs($user)->get('/account')
        ->assertSee('<span id="token-balance">1</span> credit', escape: false)
        ->assertDontSee('</span> credits', escape: false);
});

it('shows the depleted state when the ledger is empty', function () {
    config(['features.membership' => true]);
    // The Ukrainian copy of this page is the point here.
    $this->withHeader('Accept-Language', 'uk');

    // A phone-only member with no ledger rows: balance 0.
    actingAsMember()->get('/account')
        ->assertSee('<span id="token-balance">0</span> кредитів', escape: false)
        ->assertSee('id="depleted-row"', escape: false)
        ->assertSee('Примірки закінчилися. Онови або зміни тариф, щоб продовжити.')
        ->assertSee("openModal('paywall')", escape: false);
});

it('hides the depleted row while credits remain', function () {
    config(['features.membership' => true]);

    $user = User::factory()->emailOnly()->onboarded()->create();
    CreditTransaction::factory()->for($user)->create(['delta' => 2]);

    $this->actingAs($user)->get('/account')->assertDontSee('id="depleted-row"', escape: false);
});

it('falls back to the default plan on an unknown ?plan value', function () {
    config(['features.membership' => true]);

    actingAsMember()->get('/account?plan=nonsense')->assertOk()->assertSee('Shopaholic');
});

/*
 * --- /account/looks ---
 */

it('renders the looks page empty until the member has tried something on', function () {
    config(['features.membership' => true]);

    // The real grid is AccountLooksTest's; no mock cards are left.
    actingAsMember()->get('/account/looks')
        ->assertSee('My Looks')
        ->assertSee('id="looks-empty"', escape: false)
        ->assertSee('Your gallery awaits')
        ->assertDontSee('Linen-Blend Blazer');
});

/*
 * --- plan-gated sections: wardrobe & capsules ---
 */

it('locks the wardrobe below Couture and opens it from Couture up', function () {
    config(['features.membership' => true]);
    // The Ukrainian copy of this page is the point here.
    $this->withHeader('Accept-Language', 'uk');

    actingAsMember()->get('/account/wardrobe?plan=shopaholic')
        ->assertSee('id="wardrobe-locked"', escape: false)
        ->assertSee('Зберігай свої образи назавжди')
        ->assertSee('Перейти на Couture');

    actingAsMember()->get('/account/wardrobe?plan=couture')
        ->assertDontSee('id="wardrobe-locked"', escape: false)
        ->assertSee('id="wardrobe-filter"', escape: false)
        ->assertSee('Мій гардероб');
});

it('sizes the locked box to its message, so a phone does not squeeze it', function () {
    // Bug: the message was an absolute overlay on a preview only ~170px tall
    // on a phone; badge, title, copy and button were crushed into it.
    $layout = file_get_contents(resource_path('views/membership/layout.blade.php'));

    expect($layout)->toContain('.locked-box { position: relative; display: grid; grid-template-columns: minmax(0, 1fr);')
        ->and($layout)->toContain('.locked-preview, .locked-overlay { grid-area: 1 / 1; }')
        ->and($layout)->not->toContain('.locked-overlay { position: absolute; inset: 0;');
});

it('locks capsules below Couture and opens them from Couture up', function () {
    config(['features.membership' => true]);
    // The Ukrainian copy of this page is the point here.
    $this->withHeader('Accept-Language', 'uk');

    actingAsMember()->get('/account/capsules?plan=fashionista')
        ->assertSee('id="capsules-locked"', escape: false)
        ->assertSee('Капсули від стилістки');

    // Real capsules (AccountCapsulesTest); no mock looks any more.
    actingAsMember()->get('/account/capsules?plan=vip')
        ->assertDontSee('id="capsules-locked"', escape: false)
        ->assertDontSee('Desert Evening')
        ->assertSee('Капсули');
});

/*
 * --- /account/vip ---
 */

it('renders the VIP stylist block with the audio stub and verdict', function () {
    config(['features.membership' => true]);

    actingAsMember()->get('/account/vip?plan=vip')
        ->assertSee('Your Stylist')
        ->assertSee('Send capsule to stylist')
        ->assertSee('1 review available this month')
        ->assertSee('id="verdict-audio"', escape: false)
        ->assertSee('Camille Rousseau');
});

/*
 * --- /account/settings ---
 */

it('renders settings with photo slots, plan block and the danger action', function () {
    config(['features.membership' => true]);

    actingAsMember()->get('/account/settings')
        ->assertSee('id="photo-slots"', escape: false)
        ->assertSee('Change plan')
        ->assertSee('id="delete-account"', escape: false)
        ->assertSee('Delete my account')
        ->assertSee('id="confirm-delete"', escape: false)
        ->assertSee('Delete your account?');
});

it('keeps the privacy copy honest — 72 hours, never instant deletion', function () {
    config(['features.membership' => true]);

    $page = actingAsMember()->get('/account/settings')
        ->assertSee('deleted within 72 hours');

    foreach (['immediately', 'instantly', 'right away'] as $forbidden) {
        $page->assertDontSee($forbidden);
    }
});

it('locks reference-photo slots on Fashionista and frees them from Shopaholic up', function () {
    config(['features.membership' => true]);

    // Fashionista: 1 slot usable, 2 locked, upsell line shown.
    actingAsMember()->get('/account/settings?plan=fashionista')
        ->assertSee('photo-slot locked', escape: false)
        ->assertSee('Add up to 3 photos on Shopaholic and above.');

    actingAsMember()->get('/account/settings?plan=shopaholic')
        ->assertDontSee('photo-slot locked', escape: false)
        ->assertDontSee('Add up to 3 photos on Shopaholic and above.');
});

/*
 * --- navigation locks & VIP entry ---
 */

it('marks wardrobe and capsules locked in the nav below Couture', function () {
    config(['features.membership' => true]);

    // The element, not the .nav-lock CSS rule (which is always in the stylesheet).
    actingAsMember()->get('/account?plan=fashionista')->assertSee('<span class="nav-lock">', escape: false);
    actingAsMember()->get('/account?plan=couture')->assertDontSee('<span class="nav-lock">', escape: false);
});

it('shows the Stylist nav item only on the VIP plan', function () {
    config(['features.membership' => true]);

    // The nav item itself, not the bare word: the legal footer names the
    // operator ("Atelier Fit") on every page.
    actingAsMember()->get('/account?plan=vip')->assertSee('>Stylist<', escape: false);
    actingAsMember()->get('/account?plan=shopaholic')->assertDontSee('>Stylist<', escape: false);
});

/*
 * --- outside local/testing: no plan mock at all ---
 * Until billing exists a real member has no plan: the dev switcher, the plan
 * name and the plan locks must not reach production.
 */

it('hides the plan switcher and plan name from real members', function () {
    config(['features.membership' => true]);
    app()['env'] = 'production';

    actingAsMember()->get('/account')->assertOk()
        ->assertDontSee('id="plan-switcher"', escape: false)
        ->assertDontSee('class="u-plan"', escape: false)
        ->assertDontSee('Shopaholic</a>', escape: false)
        ->assertSee('id="token-balance"', escape: false);

    actingAsMember()->get('/account/settings')->assertOk()
        ->assertDontSee('id="membership-card"', escape: false)
        ->assertDontSee('Change plan');
});

it('ignores ?plan= outside local and leaves every section open', function () {
    config(['features.membership' => true]);
    app()['env'] = 'production';

    actingAsMember()->get('/account?plan=fashionista')->assertOk()
        ->assertDontSee('<span class="nav-lock">', escape: false)
        ->assertDontSee('>Stylist<', escape: false);

    actingAsMember()->get('/account/wardrobe?plan=fashionista')
        ->assertDontSee('id="wardrobe-locked"', escape: false);

    actingAsMember()->get('/account/capsules?plan=fashionista')
        ->assertDontSee('id="capsules-locked"', escape: false);

    actingAsMember()->get('/account/settings?plan=fashionista')
        ->assertDontSee('photo-slot locked', escape: false)
        ->assertDontSee('Add up to 3 photos on Shopaholic and above.');
});

it('keeps the plan switcher for local development', function () {
    config(['features.membership' => true]);
    app()['env'] = 'local';

    actingAsMember()->get('/account?plan=couture')->assertOk()
        ->assertSee('id="plan-switcher"', escape: false)
        ->assertSee('Couture');
});

/*
 * --- overlays (gate / email / otp / paywall / payment) ---
 */

it('carries every overlay in the markup', function () {
    config(['features.membership' => true]);

    $this->get('/pricing')
        ->assertSee('id="gate-modal"', escape: false)
        ->assertSee('Loved the look?')
        ->assertSee('id="email-modal"', escape: false)
        ->assertSee('Your E-mail')
        ->assertSee('id="otp-modal"', escape: false)
        ->assertSee('Enter the Code')
        ->assertSee('id="paywall-modal"', escape: false)
        ->assertSee('Your complimentary')
        ->assertSee('id="payment-modal"', escape: false)
        ->assertDontSee('token-toast', escape: false);
});

it('makes every overlay closable — X on the auth steps, Escape everywhere', function () {
    config(['features.membership' => true]);

    // The email/otp steps are full-screen (no scrim to click), so each carries
    // its own close button; the gate card gets one too (its scrim is the only
    // other way out), and Escape closes whatever overlay is open.
    $this->get('/pricing')
        ->assertSee('id="email-close"', escape: false)
        ->assertSee('id="otp-close"', escape: false)
        ->assertSee('id="gate-close"', escape: false)
        ->assertSee('keydown.escape.window="closeModal()"', escape: false);
});

it('offers a signed-in member the way back to the account from pricing', function () {
    config(['features.membership' => true]);

    // Guests see the public page as drawn — no account link.
    $this->get('/pricing')->assertDontSee('id="pricing-back-to-account"', escape: false);

    actingAsMember()->get('/pricing')
        ->assertSee('id="pricing-back-to-account"', escape: false)
        ->assertSee(route('membership.account.dashboard'), escape: false);
});

it('keeps the gate privacy line honest (72 hours)', function () {
    config(['features.membership' => true]);

    $this->get('/pricing')
        ->assertSee('Your photos are private and deleted within 72 hours.');
});

it('wires the email/otp modals to the real auth endpoints', function () {
    config(['features.membership' => true]);

    $this->get('/pricing')
        // Send Code / Verify post for real.
        ->assertSee('class="email-input"', escape: false)
        ->assertSee('Please enter a valid e-mail address.')
        ->assertSee(route('auth.email.send'), escape: false)
        ->assertSee(route('auth.email.verify'), escape: false)
        // JSON POSTs carry the CSRF token from this meta tag.
        ->assertSee('name="csrf-token"', escape: false);
});

it('shows the session card with sign-out on settings', function () {
    config(['features.membership' => true]);

    $user = User::factory()->emailOnly()->onboarded()->create();

    $this->actingAs($user)->get('/account/settings')
        ->assertSee('id="logout-button"', escape: false)
        ->assertSee('Sign out')
        ->assertSee(route('auth.logout'), escape: false)
        ->assertSee('Signed in as '.$user->email);
});
