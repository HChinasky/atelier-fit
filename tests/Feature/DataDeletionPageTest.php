<?php

/*
 * /data-deletion — the public "how to delete your data" page that Meta's app
 * settings point at. It has to answer whatever the feature flags say, and it
 * must restate the Privacy Policy, not promise more than it does.
 */

it('answers with every feature flag off', function () {
    config([
        'features.membership' => false,
        'features.fitting_room' => false,
        'features.coming_soon' => false,
    ]);

    $this->get('/data-deletion')->assertOk()->assertSee('id="data-deletion"', escape: false);
});

it('tells people where to write and links the binding policy', function () {
    $this->withHeader('Accept-Language', 'en')->get('/data-deletion')
        ->assertOk()
        ->assertSee('How to delete your data')
        ->assertSee('mailto:'.config('legal.privacy_email'), escape: false)
        ->assertSee(config('legal.base_url').'/privacy-policy', escape: false)
        ->assertSee('within 30 days');
});

it('states the photo deadline as within 72 hours, never as instant', function (string $locale, string $deadline) {
    $html = $this->withHeader('Accept-Language', $locale)->get('/data-deletion')->assertOk()->getContent();

    expect($html)->toContain($deadline)
        ->and(mb_strtolower($html))->not->toContain('immediately')
        ->not->toContain('instantly')
        ->not->toContain('одразу')
        ->not->toContain('сразу');
})->with([
    ['en', 'within 72 hours'],
    ['uk', 'протягом 72 годин'],
    ['ru', 'в течение 72 часов'],
    ['ar', 'خلال 72 ساعة'],
]);

it('does not offer a self-service deletion that does not exist', function () {
    $html = mb_strtolower($this->withHeader('Accept-Language', 'en')->get('/data-deletion')->getContent());

    expect($html)->not->toContain('delete account')
        ->not->toContain('delete my account');
});
