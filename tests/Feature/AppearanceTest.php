<?php

/*
 * Brand appearance: the site runs on a four-color monochrome palette
 * (#2D2D2D ink, #FFFFFF base, #F0F0F0 second surface, #6B6B6B muted) and
 * carries the IR monogram favicon. These are cheap regression guards —
 * they pin the palette contract, not individual rules.
 */

use App\Models\User;

/** Every color literal a Blade view is allowed to contain. */
$allowedColors = [
    '#2D2D2D', '#FFFFFF', '#F0F0F0', '#6B6B6B', // brand palette (#F0F0F0 replaced the cream #FBF8F3)
    '#A6432E', '#8F3826',                       // error red (states must read as errors)
    '#C8102E', '#009739', '#111',               // UAE flag in the phone prefix
    '#000', '#FFF',                             // Apple Pay button
];

/*
 * The "Примірка" screens — the phone's dashboard and the two steps
 * (membership/tryon/*) — draw their lines in two greys of their own, from the
 * palette table of the design hand-over (2026-10-20): --border for outlines,
 * --dashed for the upload card. Those views only.
 */
$tryOnColors = ['#E6E6E6', '#BDBDBD'];

it('paints every view with the brand palette only', function () use ($allowedColors, $tryOnColors) {
    $offenders = [];

    foreach (glob(resource_path('views').'/{,*/,*/*/}*.blade.php', GLOB_BRACE) as $file) {
        preg_match_all('/#[0-9A-Fa-f]{3}\b|#[0-9A-Fa-f]{6}\b/', file_get_contents($file), $matches);
        $path = str_replace('\\', '/', $file);
        $tryOnView = str_ends_with($path, '/membership/dashboard.blade.php') || str_contains($path, '/membership/tryon/');
        $allowed = $tryOnView ? [...$allowedColors, ...$tryOnColors] : $allowedColors;

        foreach (array_unique($matches[0]) as $color) {
            if (! in_array(strtoupper($color), $allowed, true)) {
                $offenders[] = basename($file).': '.$color;
            }
        }
    }

    expect($offenders)->toBe([]);
});

/*
 * Every face must set both English and Ukrainian. Bodoni Moda has no Cyrillic,
 * so Ukrainian headings fell back to a system serif; Playfair Display replaced it.
 */
it('uses only fonts that cover Ukrainian as well as English', function () {
    $script = file_get_contents(base_path('scripts/fetch-vendor-assets.sh'));
    preg_match('/^FONT_CSS_URL="([^"]+)"/m', $script, $url);
    preg_match_all('/family=([^:&]+)/', $url[1], $families);

    // Each of these ships a Cyrillic subset with і, ї, є and ґ.
    expect(array_map(fn (string $family) => urldecode($family), $families[1]))
        ->toBe(['Playfair Display', 'Montserrat', 'Prata', 'Great Vibes', 'Manrope']);

    // Bodoni Moda had no Cyrillic; Jost's Cyrillic lacks the Ukrainian і ї є ґ,
    // which then came from a system font. Neither may come back.
    $offenders = [];
    foreach (glob(resource_path('views').'/{,*/,*/*/}*.blade.php', GLOB_BRACE) as $file) {
        $view = file_get_contents($file);
        if (str_contains($view, 'Bodoni') || str_contains($view, 'Jost')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});

/*
 * A piece or a look is always shown whole. A centred crop (object-fit: cover)
 * cut long dresses at the hem and the top on card after card; only faces,
 * avatars, flags and decorative pictures may still be cropped.
 */
it('versions the fonts stylesheet, so a font swap reaches visitors at once', function () {
    // Bug: Cloudflare kept the old fonts.css (max-age 4h) after Montserrat
    // replaced Jost, and the pages went on loading Jost.
    $link = '<link rel="stylesheet" href="{{ asset(\'fonts/fonts.css\') }}?v={{ @filemtime(public_path(\'fonts/fonts.css\')) }}">';

    foreach (['membership/layout', 'membership/tryon/layout', 'ai-fitting-room', 'stylist/color/layout', 'admin/color/index'] as $view) {
        $source = file_get_contents(resource_path("views/{$view}.blade.php"));
        expect($source)->toContain($link)
            ->and(substr_count($source, 'fonts/fonts.css'))->toBe(2); // the link and its mtime
    }
});

it('never crops a piece or a look', function () {
    $whole = [
        'ai-fitting-room.blade.php' => ['.ncard-img {', '.look-thumb img {'],
        'membership/capsule.blade.php' => ['.piece-card .pic img {', '.way-card .lead img {', '.way-card .minis img {'],
        'membership/layout.blade.php' => ['.compare img {'],
        'membership/wardrobe.blade.php' => ['.look-card .pic img {', '.look-visual > img {', '.piece .pic img {', '.fav-card .pic img {', '.compare-pic img {'],
        'membership/tryon/select.blade.php' => ['.t2-card-pic img {', '.t2-result img {'],
        'membership/dashboard.blade.php' => ['.frame-2x3 img {', '.cmp-pick img {', '.pd-thumb img {', '.pm-track img {', '.mt-card-pic img {', '.m-cmp-pick img {', '.chosen-card .pic img {', '.pick-card .pic img {'],
    ];

    $cropped = [];
    foreach ($whole as $view => $selectors) {
        $css = file_get_contents(resource_path('views/'.$view));
        foreach ($selectors as $selector) {
            preg_match_all('/'.preg_quote($selector, '/').'[^}]*\}/', $css, $rules);
            expect($rules[0])->not->toBeEmpty();
            foreach ($rules[0] as $rule) {
                if (str_contains($rule, 'object-fit: cover')) {
                    $cropped[] = $view.': '.$selector;
                }
            }
        }
    }

    expect($cropped)->toBe([]);
});

it('brands every user-facing page as Atelier Fit', function (string $route) {
    config(['features.membership' => true]);

    $this->actingAs(User::factory()->emailOnly()->onboarded()->create())
        ->get($route)
        ->assertOk()
        ->assertSee('Atelier Fit')
        ->assertDontSee('NOOR');
})->with(['/ai-fitting-room', '/pricing', '/account', '/account/try-on/step-1', '/account/looks', '/account/settings']);

it('brands the sign-in page for guests too', function () {
    config(['features.membership' => true]);

    $this->get('/')->assertOk()->assertSee('Atelier Fit')->assertDontSee('NOOR');
});

it('serves the favicon file from the public root', function () {
    expect(file_exists(public_path('favicon.png')))->toBeTrue();
});

it('links the favicon on the guest page and inside the account', function () {
    config(['features.membership' => true]);

    $this->get('/ai-fitting-room')->assertSee('favicon.png');

    $this->actingAs(User::factory()->emailOnly()->onboarded()->create())
        ->get('/account')
        ->assertSee('favicon.png');
});
