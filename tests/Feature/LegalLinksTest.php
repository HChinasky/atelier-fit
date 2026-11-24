<?php

use App\Models\User;

// This file checks the English copy: the pages are asked for in English
// (the suite's default language is APP_LOCALE in phpunit.xml).
beforeEach(fn () => $this->withHeader('Accept-Language', 'en'));

/*
 * Where the legal documents are linked from the interface.
 *
 * The sign-in page links the Terms and the Privacy Policy. The account-wide
 * legal footer that linked every document was removed on the owner's call
 * (2026-10-14); the test below keeps it from coming back unnoticed.
 */

beforeEach(fn () => config(['features.membership' => true]));

it('offers the terms and the privacy policy on the sign-in page', function () {
    $html = $this->get('/')->assertOk()->getContent();

    // HTML pages, not the PDFs: Google's OAuth verification could not open the PDF.
    expect($html)->toContain(route('legal.show', 'terms-of-use'))
        ->and($html)->toContain(route('legal.show', 'privacy-policy'));
});

it('states on sign-in that continuing accepts the terms, without asking for a tick', function () {
    $html = $this->get('/')->assertOk()->getContent();

    // A notice, not a consent box: signing in is necessary to provide the
    // service, so a checkbox here would ask permission for something nobody can
    // refuse. Consent that cannot be withheld is not consent.
    expect($html)->toContain('By continuing you agree to the');

    $afterNotice = Str::of($html)->after('login-legal')->before('</p>')->toString();
    expect($afterNotice)->not->toContain('type="checkbox"');
});

it('carries no legal footer on the account or pricing pages (the owner\'s call, 2026-10-14)', function () {
    $user = User::factory()->emailOnly()->onboarded()->create();

    foreach ([$this->actingAs($user)->get('/account'), $this->get('/pricing')] as $response) {
        $html = $response->assertOk()->getContent();

        expect($html)->not->toContain('class="legal-footer"')
            ->and($html)->not->toContain(e(config('legal.operator_line')));
    }
});

it('names the operator identically in the config and in the Legal Notice', function () {
    // Who runs this service has to read the same wherever it is stated: the
    // Legal Notice, and the brand's own site.
    $line = config('legal.operator_line');
    $notice = file_get_contents(base_path('docs/legal/legal-notice.md'));

    expect($notice)->toContain($line);
});

it('does not link the consent wording as if it were a page', function () {
    // consent.md is the text of a checkbox plus notes on building it, not
    // something to send a reader to.
    expect(array_keys(config('legal.documents')))->not->toContain('consent');
});

/*
 * Subscription terms before payment (pricing page).
 */

it('shows the subscription terms and both policy links on the pricing page', function () {
    $html = $this->get('/pricing')->assertOk()->getContent();

    expect($html)->toContain('Before you subscribe')
        ->and($html)->toContain('renews automatically until you cancel it')
        ->and($html)->toContain('cannot be exchanged for money')
        ->and($html)->toContain('not deducted or it is returned')
        ->and($html)->toContain('one written review of one saved look per month')
        ->and($html)->toContain('within 5 working days')
        ->and($html)->toContain('not a guarantee of exact fit')
        ->and($html)->toContain('All prices are in UAE dirhams (AED).')
        ->and($html)->toContain(config('legal.base_url').'/terms-of-use')
        ->and($html)->toContain(config('legal.base_url').'/refund-policy')
        ->and($html)->toContain('mailto:'.config('legal.privacy_email'));
});

it('says nothing about VAT until it is decided, then states it', function () {
    config(['membership.prices_include_vat' => null]);
    expect($this->get('/pricing')->getContent())->not->toContain('VAT');

    config(['membership.prices_include_vat' => true]);
    expect($this->get('/pricing')->getContent())->toContain('Prices include VAT.');

    config(['membership.prices_include_vat' => false]);
    expect($this->get('/pricing')->getContent())->toContain('Prices do not include VAT');
});

it('publishes a refund policy that does not promise "no refunds"', function () {
    $md = file_get_contents(base_path('docs/legal/refund-policy.md'));

    expect(array_keys(config('legal.documents')))->toContain('refund-policy')
        ->and($md)->toContain('info@atelierfit.example')
        ->and($md)->toContain('original payment method')
        ->and($md)->toContain('Charged twice')
        ->and(strtolower($md))->not->toContain('no refunds')
        ->and(strtolower($md))->not->toContain('sole discretion')
        ->and(strtolower($md))->not->toContain('any failure');
});

it('puts the subscription terms into the Terms of Use', function () {
    $md = file_get_contents(base_path('docs/legal/terms-of-use.md'));

    expect($md)->toContain('renews automatically every month until you cancel it')
        ->and($md)->toContain('expire at the end of that month')
        ->and($md)->toContain('10 minutes')
        ->and($md)->toContain('5 working days')
        ->and($md)->not->toContain('No payments are taken by this service')
        ->and(config('terms.version'))->toBe('2');
});

it('states by default that the prices include VAT, in step with the Terms', function () {
    expect(config('membership.prices_include_vat'))->toBeTrue()
        ->and(file_get_contents(base_path('docs/legal/terms-of-use.md')))->toContain('All prices include VAT.')
        ->and($this->get('/pricing')->getContent())->toContain('Prices include VAT.');
});
