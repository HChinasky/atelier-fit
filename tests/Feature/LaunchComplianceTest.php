<?php

use Illuminate\Support\Facades\File;

// This file checks the English copy: the pages are asked for in English
// (the suite's default language is APP_LOCALE in phpunit.xml).
beforeEach(fn () => $this->withHeader('Accept-Language', 'en'));

/*
 * Guards three launch conditions that came out of legal review and are easy to
 * regress silently, because nothing about them breaks a page.
 */

// ---- own photo only ----

it('asks consent for the visitor\'s own photo, not for someone else\'s', function () {
    $text = (string) config('consent.text');

    // A person cannot give explicit consent on behalf of the person in the photo,
    // so the old "another adult who agreed" permission is gone.
    expect($text)->not->toContain('adult who agreed')
        ->and($text)->not->toContain('another adult')
        ->and(strtolower($text))->toContain('the photo is of me');
});

it('confirms age and ownership in the same consent', function () {
    $text = strtolower((string) config('consent.text'));

    expect($text)->toContain('at least 18')
        ->and($text)->toContain('mine to upload');
});

it('keeps the third-party-subject flow switched off', function () {
    // The seam survives for a later flow that collects consent from the person
    // depicted; nothing may read it as permission today.
    expect(config('consent.allow_third_party_subject'))->toBeFalse();
});

it('does not promise a flat 72 hours for copies we do not control', function () {
    // Our copy is deleted; the AI provider keeps its own under its own retention.
    $text = strtolower((string) config('consent.text'));

    expect($text)->toContain('our copy')
        ->and($text)->toContain('own retention');
});

// ---- no third-party asset hosts ----

it('loads no fonts or scripts from a third-party CDN', function () {
    // Every such request handed the visitor's IP to that company before the page
    // rendered. Turnstile is deliberately exempt below: it is a security control,
    // not an asset host.
    $offenders = [];

    foreach (File::allFiles(resource_path('views')) as $file) {
        $contents = $file->getContents();

        // posthog.com guards the analytics promise specifically: usage analytics
        // is SERVER-side only, so no template may ever load a PostHog snippet.
        foreach (['fonts.googleapis.com', 'fonts.gstatic.com', 'cdn.jsdelivr.net', 'posthog.com'] as $host) {
            if (str_contains($contents, $host)) {
                $offenders[] = $file->getRelativePathname().' → '.$host;
            }
        }
    }

    expect($offenders)->toBeEmpty();
});

it('still loads Cloudflare Turnstile, which is a security control and stays', function () {
    $layout = File::get(resource_path('views/membership/layout.blade.php'));

    expect($layout)->toContain('challenges.cloudflare.com');
});

// ---- pricing is visibly not for sale ----

it('says on the pricing page itself that memberships cannot be bought yet', function () {
    config(['features.membership' => true]);

    $html = $this->get('/pricing')->assertOk()->getContent();

    // On the page, not only in the Terms: prices shown without it read as an offer.
    expect($html)->toContain('Coming soon')
        ->and($html)->toContain('not available for purchase');
});

it('leaves no live-looking way to subscribe on the pricing page', function () {
    config(['features.membership' => true]);

    $html = $this->get('/pricing')->assertOk()->getContent();

    expect($html)->not->toContain("openModal('payment')")
        ->and($html)->not->toContain('Secure payment ·');
});
