<?php

use App\Models\Generation;
use App\Models\Lead;
use App\Models\Product;
use App\Models\User;

/*
 * Every client page renders whole in every supported language: in English
 * and Arabic no Cyrillic is left in what a visitor reads, in Ukrainian,
 * Russian and Arabic no stray English, and in no language a raw
 * translation key ("dashboard.save") leaks onto the page.
 *
 * Not covered: the colour page (/account/color), whose copy is still to be
 * moved into lang/, and the photo-consent and Terms wording, which is legal
 * text versioned in config/consent.php and config/terms.php.
 */

beforeEach(fn () => config(['features.membership' => true]));

/**
 * What a visitor can read: the page without its styles, HTML comments and
 * script comments, and without the switcher's language names, which are
 * written in their own language on purpose.
 */
function visibleText(string $html): string
{
    $html = preg_replace('#<style\b.*?</style>#s', '', $html);
    $html = preg_replace('#<!--.*?-->#s', '', $html);
    $html = preg_replace_callback('#<script\b.*?</script>#s', fn (array $m) => preg_replace(
        ['#/\*.*?\*/#s', '#(^|[\s;{}(),])//[^\n]*#'],
        ['', '$1'],
        $m[0],
    ), $html);

    $names = array_column((array) config('locales.supported'), 'name');

    return str_replace($names, '', $html);
}

function rawKeysIn(string $html): array
{
    $groups = 'common|login|account|dashboard|wardrobe|capsules|onboarding|pages|plans|modals|requests|generation|mail|coming_soon|catalog|tryon_steps|data_deletion';
    preg_match_all('/\b(?:'.$groups.')\.[a-z_]+(?:\.[a-z_]+)*\b/', $html, $m);

    // A file name ("wardrobe.svg") is not a key, nor is the dashboard's localStorage key.
    return array_values(array_unique(array_filter(
        $m[0],
        fn (string $key) => ! preg_match('/\.(svg|png|jpe?g|webp|css|js)$/', $key) && $key !== 'account.liked',
    )));
}

dataset('client pages', [
    'sign-in' => ['/', false],
    'pricing' => ['/pricing', false],
    'dashboard' => ['/account', true],
    'looks' => ['/account/looks', true],
    'wardrobe' => ['/account/wardrobe?plan=couture', true],
    'wardrobe, locked' => ['/account/wardrobe?plan=shopaholic', true],
    'capsules' => ['/account/capsules?plan=couture', true],
    'capsules, locked' => ['/account/capsules?plan=fashionista', true],
    'stylist' => ['/account/vip?plan=vip', true],
    'settings' => ['/account/settings', true],
    'style quiz' => ['/account/welcome', true],
    'try-on step 1' => ['/account/try-on/step-1', true],
    'data deletion' => ['/data-deletion', false],
]);

it('leaves no Cyrillic on a page asked for in English or Arabic', function (string $url, bool $member, string $locale) {
    if ($member) {
        $this->actingAs(User::factory()->emailOnly()->onboarded()->create(['name' => 'Layla Haddad']));
    }

    $html = $this->withHeader('Accept-Language', $locale)->get($url)->assertOk()->getContent();
    expect($html)->toContain('<html lang="'.$locale.'"');

    preg_match_all('/[^\s<>"]*[\x{0400}-\x{04FF}]+[^\s<>"]*/u', visibleText($html), $m);
    expect(array_values(array_unique($m[0])))->toBe([])
        ->and(rawKeysIn($html))->toBe([]);
})->with('client pages')->with(['en', 'ar']);

it('leaks no translation key onto a page in Ukrainian or Russian', function (string $url, bool $member, string $locale) {
    if ($member) {
        $this->actingAs(User::factory()->emailOnly()->onboarded()->create());
    }

    $html = $this->withHeader('Accept-Language', $locale)->get($url)->assertOk()->getContent();

    expect(rawKeysIn($html))->toBe([])
        ->and($html)->toContain('<html lang="'.$locale.'"');
})->with('client pages')->with(['uk', 'ru']);

/*
 * The other direction: English left on a Ukrainian, Russian or Arabic page. Latin letters there
 * may only be names — the brand, plans, providers, file formats, the feed's
 * brands and pieces — or the legal wording that is not translated yet.
 */
const LATIN_ON_UK_PAGES = [
    'Atelier', 'Fit', 'by', 'AI', 'Fitting', 'Room', 'FittingRoom', 'Try', 'On',
    'FREE', 'Fashionista', 'Shopaholic', 'Couture', 'Collector', 'Diva', 'VIP', 'Starter', 'Style', 'Pro', 'Unlimited',
    'Google', 'Apple', 'Pay', 'Visa', 'Mastercard', 'Stripe', 'Checkout', 'com',
    'Instagram', 'TikTok', 'Facebook', 'LinkedIn', 'Pinterest', 'YouTube', 'Threads', 'WhatsApp', 'Email',
    'JPG', 'PNG', 'WebP', 'AED', 'IP', 'XS', 'XL', 'XXL', 'UA', 'EN', 'RU', 'AR', 'English',
    // Words the owner's Ukrainian copy keeps in English.
    'mail', 'fashion', 'style',
    // The links beside the consent and Terms wording, which stay English with it.
    'Terms', 'Read', 'them', 'Privacy', 'Policy',
    // Mock data: the looks page cards, the stylist demo, the dev-only plan switcher.
    'Mango', 'Zara', 'Massimo', 'Dutti', 'Linen', 'Blend', 'Blazer', 'Pleated', 'Midi', 'Dress', 'Silk', 'Slip',
    'Wool', 'Trench', 'Coat', 'Wide', 'Leg', 'Trousers', 'Cashmere', 'Knit', 'Camille', 'Rousseau', 'Empty',
];

it('leaves no stray English on a page asked for in Ukrainian, Russian or Arabic', function (string $url, bool $member, string $locale) {
    if ($member) {
        $this->actingAs(User::factory()->emailOnly()->onboarded()->create(['name' => 'Олена']));
    }

    $html = $this->withHeader('Accept-Language', $locale)->get($url)->assertOk()->getContent();
    $html = preg_replace(['#<style\b.*?</style>#s', '#<script\b.*?</script>#s', '#<!--.*?-->#s', '#<svg\b.*?</svg>#s'], '', $html);
    $html = str_replace([e(config('consent.text')), e(config('terms.text'))], '', $html);

    preg_match_all('#>([^<]+)<#', $html, $nodes);
    preg_match_all('#(?<![:\w-])(?:placeholder|aria-label|title|alt)="([^"]*)"#', $html, $attributes);

    $stray = [];
    foreach (array_merge($nodes[1], $attributes[1]) as $text) {
        // A fragment of an Alpine expression is code, not text.
        if (preg_match('/[=$(){};"]|\?\.|\|\||&&/', $text)) {
            continue;
        }
        $text = preg_replace('/\S+@\S+/', '', html_entity_decode($text));
        preg_match_all('/[A-Za-z]{2,}/', $text, $words);
        $stray = array_merge($stray, array_diff(array_map('strtolower', $words[0]), array_map('strtolower', LATIN_ON_UK_PAGES)));
    }

    expect(array_values(array_unique($stray)))->toBe([]);
})->with('client pages')->with(['uk', 'ru', 'ar']);

it('dates a look card and notes an expired image in the page language', function () {
    $user = User::factory()->emailOnly()->onboarded()->create();
    Generation::factory()->done()->create([
        'lead_id' => Lead::factory()->forUser($user)->create()->id,
        'product_id' => Product::factory()->verified()->create()->id,
        'result_urls' => null,
        'created_at' => '2026-07-02 12:00:00',
    ]);
    $this->actingAs($user);

    $this->withHeader('Accept-Language', 'uk')->get('/account/looks')
        ->assertSee('2 лип')
        ->assertSee('Зображення вже не зберігається')
        ->assertDontSee('Jul 2');

    $this->withHeader('Accept-Language', 'en')->get('/account/looks')
        ->assertSee('2 Jul')
        ->assertSee('Image no longer stored');

    $this->withHeader('Accept-Language', 'ru')->get('/account/looks')
        ->assertSee('2 июл')
        ->assertSee('Изображение уже не хранится');

    $this->withHeader('Accept-Language', 'ar')->get('/account/looks')
        ->assertSee('يوليو')
        ->assertSee('لم تعد الصورة محفوظة')
        ->assertSee('<html lang="ar" dir="rtl"', escape: false);
});

it('renders the coming-soon curtain in both languages', function () {
    config(['features.coming_soon' => true]);

    $this->withHeader('Accept-Language', 'en')->get('/coming-soon')->assertOk()
        ->assertSee('Your fitting room is almost ready.')
        ->assertSee('id="soon-locale"', escape: false);

    $this->withHeader('Accept-Language', 'uk')->get('/coming-soon')->assertOk()
        ->assertSee('Твоя примірочна майже готова.');
});

it('words the plan cards and the quiz in the page language', function () {
    $member = User::factory()->emailOnly()->onboarded()->create();

    $this->actingAs($member)->withHeader('Accept-Language', 'en')->get('/account')
        ->assertSee('Choose Fashionista')
        ->assertSee('AED 749 / month');
    $this->actingAs($member)->withHeader('Accept-Language', 'uk')->get('/account')
        ->assertSee('Обрати Fashionista')
        ->assertSee('AED 749 / місяць');

    $this->actingAs($member)->withHeader('Accept-Language', 'en')->get('/account/welcome')
        ->assertSee('Is your hair colour natural right now?')
        ->assertSee('Under 200 AED');
});

it('counts credits in the forms of each language', function (string $locale, int $count, string $words) {
    expect(trans_choice('common.credits', $count, [], $locale))->toBe($words);
})->with([
    ['uk', 1, 'кредит'],
    ['uk', 3, 'кредити'],
    ['uk', 5, 'кредитів'],
    ['uk', 11, 'кредитів'],
    ['uk', 21, 'кредит'],
    ['en', 1, 'credit'],
    ['en', 0, 'credits'],
    ['en', 7, 'credits'],
    ['ru', 1, 'кредит'],
    ['ru', 3, 'кредита'],
    ['ru', 5, 'кредитов'],
    ['ru', 21, 'кредит'],
    ['ar', 1, 'رصيد'],
    ['ar', 3, 'أرصدة'],
    ['ar', 11, 'رصيدًا'],
    ['ar', 100, 'رصيد'],
]);
