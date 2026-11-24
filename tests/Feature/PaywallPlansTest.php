<?php

use App\Models\User;

/*
 * The paywall modal shows the plans themselves, so someone who has run out of
 * try-ons sees that a plan is what they need. The plans are a preview: no payment
 * provider is connected, so the buttons stay inert and the modal says so - the
 * same honesty /pricing has.
 */

beforeEach(function () {
    config(['features.membership' => true]);
    $this->withHeader('Accept-Language', 'en');
});

it('lists every plan inside the paywall modal, with the same names and prices as /pricing', function () {
    $html = $this->get('/pricing')->assertOk()->getContent();

    expect($html)->toContain('id="paywall-plans"');

    $modal = substr($html, strpos($html, 'id="paywall-modal"'));
    $modal = substr($modal, 0, strpos($modal, 'id="payment-modal"'));

    foreach (['Fashionista' => 'AED 39', 'Shopaholic' => 'AED 99', 'Couture' => 'AED 269', 'Diva VIP' => 'AED 749'] as $name => $price) {
        expect($modal)->toContain($name)->toContain($price);
    }
});

it('keeps the plan buttons inert and says nothing can be bought yet', function () {
    $html = $this->get('/pricing')->getContent();
    $modal = substr($html, strpos($html, 'id="paywall-modal"'));
    $modal = substr($modal, 0, strpos($modal, 'id="payment-modal"'));

    expect(substr_count($modal, 'class="plan-mini-btn" type="button" disabled'))->toBe(4);
    expect($modal)->toContain('id="paywall-soon"')->toContain('Memberships are not available for purchase yet');
});

it('no longer promises a subscription it cannot sell', function () {
    $html = $this->get('/pricing')->getContent();
    $modal = substr($html, strpos($html, 'id="paywall-modal"'));
    $modal = substr($modal, 0, strpos($modal, 'id="payment-modal"'));

    expect($modal)->not->toContain('Cancel anytime')->not->toContain('from AED 39/mo');
});

it('still leads to the full pricing page', function () {
    $this->get('/pricing')->assertSee('Compare plans in detail');
});

it('shows the same plans on the dashboard, where the paywall opens', function () {
    $this->actingAs(User::factory()->emailOnly()->create())
        ->get('/account?pick=1')
        ->assertSee('id="paywall-plans"', escape: false);
});
