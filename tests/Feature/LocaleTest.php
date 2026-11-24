<?php

use App\Http\Middleware\SetLocale;
use App\Mail\LoginCode;
use App\Models\Generation;
use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\CategoryChips;
use App\Services\Locale\JsText;
use App\Services\Locale\Locales;
use App\Services\Onboarding\StyleQuiz;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/*
 * The interface language: how a request picks it (SetLocale / Locales), how
 * a choice is kept (session, cookie, account), the switcher, and the places
 * that must follow it — mail, JSON messages — or must not (the internal tools).
 */

beforeEach(fn () => config(['features.membership' => true]));

function localeMember(array $attributes = []): User
{
    return User::factory()->emailOnly()->onboarded()->create($attributes);
}

/** The full e-mail sign-in, as the browser does it. */
function signInByCode(string $email): void
{
    test()->postJson(route('auth.email.send'), ['email' => $email, 'fingerprint' => 'fp-locale'])->assertOk();

    test()->postJson(route('auth.email.verify'), [
        'email' => $email,
        'code' => Mail::sent(LoginCode::class)->last()->code,
    ])->assertOk();
}

// ---- which language a request gets ----

it('speaks the configured default when the browser asks for nothing it supports', function () {
    config(['app.locale' => 'uk']);

    $this->withHeader('Accept-Language', 'fr-FR,fr;q=0.9')->get('/')
        ->assertOk()
        ->assertSee('<html lang="uk" dir="ltr"', escape: false)
        ->assertSee('З поверненням')
        ->assertHeader('Content-Language', 'uk');
});

it('falls back to the first supported language when APP_LOCALE is not one of them', function () {
    config(['app.locale' => 'de']);

    expect(Locales::fallback())->toBe('uk');
});

it('follows the browser among the supported languages, region and all', function (string $header, string $expected) {
    $this->withHeader('Accept-Language', $header)->get('/')
        ->assertSee('<html lang="'.$expected.'"', escape: false)
        ->assertHeader('Content-Language', $expected);
})->with([
    'English' => ['en-GB,en;q=0.9', 'en'],
    'Ukrainian with a region' => ['uk-UA,uk;q=0.9,en;q=0.5', 'uk'],
    'the first supported one wins' => ['de-DE,en;q=0.8,uk;q=0.7', 'en'],
]);

it('tells shared caches that the page differs by language', function () {
    $vary = $this->get('/')->headers->get('Vary');

    expect($vary)->toContain('Accept-Language');
});

it('takes ?lang= as a choice and keeps it for the next page', function () {
    $this->withHeader('Accept-Language', 'uk')->get('/?lang=en')
        ->assertSee('Welcome Back')
        ->assertSessionHas(Locales::SESSION_KEY, 'en')
        ->assertCookie(Locales::COOKIE, 'en');

    // No ?lang now, and a Ukrainian browser: the choice still holds.
    $this->get('/pricing')->assertSee('<html lang="en"', escape: false);
});

it('ignores a ?lang= it does not support', function () {
    $this->get('/?lang=xx')
        ->assertSessionMissing(Locales::SESSION_KEY)
        ->assertSee('<html lang="uk"', escape: false);
});

it('remembers a guest by the cookie once the session is gone', function () {
    $this->withCookie(Locales::COOKIE, 'en')->get('/')
        ->assertSee('<html lang="en"', escape: false);
});

it('gives a member the language saved on the account', function () {
    $this->actingAs(localeMember())->get('/account/settings')
        ->assertSee('<html lang="uk"', escape: false);

    $this->actingAs(localeMember(['locale' => 'en']))->get('/account/settings')
        ->assertSee('<html lang="en"', escape: false)
        ->assertSee('Profile &amp; Settings', escape: false);
});

it('ignores a saved language that is no longer supported', function () {
    $this->actingAs(User::factory()->emailOnly()->onboarded()->speaking('de')->create())->get('/account/settings')
        ->assertSee('<html lang="uk"', escape: false);
});

// ---- the switcher ----

it('switches the language and goes back to the page it was on', function () {
    $this->withHeader('referer', 'http://localhost/pricing')
        ->get(route('locale.switch', 'en'))
        ->assertRedirect('http://localhost/pricing')
        ->assertSessionHas(Locales::SESSION_KEY, 'en')
        ->assertCookie(Locales::COOKIE, 'en');
});

it('never sends the switch back to another site', function () {
    $this->withHeader('referer', 'https://evil.example/phish')
        ->get(route('locale.switch', 'en'))
        ->assertRedirect(route('login'));

    $this->get(route('locale.switch', 'uk'))->assertRedirect(route('login'));
});

it('404s a language that is not supported', function () {
    $this->get('/locale/de')->assertNotFound();
    $this->get('/locale/fr')->assertNotFound();
});

it('saves the choice on the account of a member who switches', function () {
    $member = localeMember();

    $this->actingAs($member)->get(route('locale.switch', 'en'))->assertRedirect();

    expect($member->fresh()->locale)->toBe('en');
});

it('draws the switcher on the sign-in page, the profile and the sidebar', function () {
    $this->get('/')
        ->assertSee('id="login-locale"', escape: false)
        ->assertSee('href="'.route('locale.switch', 'en').'"', escape: false)
        // The current language is a mark, not a link to itself.
        ->assertDontSee('href="'.route('locale.switch', 'uk').'"', escape: false);

    $this->actingAs(localeMember())->get('/account/settings')
        ->assertSee('id="language-card"', escape: false)
        ->assertSee('id="settings-locale"', escape: false)
        ->assertSee('id="sidebar-locale"', escape: false);
});

// ---- signing in ----

it('puts a choice made before signing in onto the new account', function () {
    Mail::fake();

    $this->get('/?lang=en')->assertOk();
    signInByCode('first@example.test');

    expect(User::where('email', 'first@example.test')->sole()->locale)->toBe('en');
});

it('hands the account its own language at sign-in when this device made no choice', function () {
    Mail::fake();
    localeMember(['email' => 'back@example.test', 'locale' => 'en']);

    signInByCode('back@example.test');

    expect(session(Locales::SESSION_KEY))->toBe('en');
    $this->get('/account/settings')->assertSee('<html lang="en"', escape: false);
});

// ---- what follows the language ----

it('sends the sign-in code in the language of the page it was asked from', function () {
    Mail::fake();

    $this->withHeader('Accept-Language', 'en')
        ->postJson(route('auth.email.send'), ['email' => 'en@example.test', 'fingerprint' => 'fp-1'])->assertOk();
    $english = Mail::sent(LoginCode::class)->last();
    expect($english->envelope()->subject)->toContain('is your Atelier Fit sign-in code');

    $this->withHeader('Accept-Language', 'uk')
        ->postJson(route('auth.email.send'), ['email' => 'uk@example.test', 'fingerprint' => 'fp-2'])->assertOk();
    $ukrainian = Mail::sent(LoginCode::class)->last();
    expect($ukrainian->envelope()->subject)->toContain('твій код для входу')
        ->and($ukrainian->render())->toContain('<html lang="uk">');
});

it('answers the JSON endpoints in the visitor language', function () {
    $this->withHeader('Accept-Language', 'en')->postJson(route('fitting-room.generate'), [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.product_id.0', 'Please pick a piece to try on.');

    $this->withHeader('Accept-Language', 'uk')->postJson(route('fitting-room.generate'), [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.product_id.0', 'Оберіть, будь ласка, річ для примірки.');
});

it('keeps Laravel own validation messages in Ukrainian too', function () {
    app()->setLocale('uk');

    $errors = validator(['email' => 'nope'], ['email' => 'email'])->errors();

    // The field is named in Ukrainian too (validation.attributes), not "email".
    expect($errors->first('email'))->toBe('Поле e-mail має бути дійсною адресою e-mail.');
});

it('holds the internal tools to English whatever the visitor picked', function () {
    config(['features.color_analysis' => true]);
    $admin = User::factory()->emailOnly()->create();
    $admin->forceFill(['is_admin' => true])->save();

    $this->actingAs($admin)->withHeader('Accept-Language', 'uk')->get('/admin/color')
        ->assertOk()
        ->assertHeader('Content-Language', 'en');
});

it('runs on every web and api request', function () {
    $groups = app(Kernel::class)->getMiddlewareGroups();
    $web = $groups['web'] ?? [];
    $api = $groups['api'] ?? [];

    expect($web)->toContain(SetLocale::class)
        ->and($api)->toContain(SetLocale::class);
});

// ---- JS literals ----

it('writes translated text as a safe JS literal that keeps its letters', function () {
    expect(JsText::from('Посилання скопійовано'))->toBe("'Посилання скопійовано'")
        // Everything that could close the attribute, the string or the script is escaped.
        ->and(JsText::from("з'явиться \"тут\" </script> & co"))
        ->toBe("'з\\u0027явиться \\u0022тут\\u0022 \\u003C/script\\u003E \\u0026 co'");
});

// ---- data worded at run time ----

it('words the catalog chips in the visitor language, one chip for two feeds', function () {
    $products = [
        Product::factory()->make(['feed_category' => 'Платья']),
        Product::factory()->make(['feed_category' => 'Dresses']),
        Product::factory()->make(['feed_category' => 'Юбки']),
    ];

    app()->setLocale('uk');
    expect(CategoryChips::for($products))->toBe(['Сукні', 'Спідниці'])
        ->and($products[0]->category_label)->toBe('Сукні');

    app()->setLocale('en');
    expect(CategoryChips::for($products))->toBe(['Dresses', 'Skirts'])
        ->and($products[1]->category_label)->toBe('Dresses');
});

it('asks the style quiz in the visitor language with the same answers', function () {
    app()->setLocale('en');
    $english = StyleQuiz::questions();
    app()->setLocale('uk');
    $ukrainian = StyleQuiz::questions();

    expect($english['metal']['question'])->toBe('Which jewellery suits you better?')
        ->and($ukrainian['metal']['question'])->toBe('Які прикраси тобі більше до лиця?')
        // The stored values do not depend on the language.
        ->and(array_keys($english['budget']['options']))->toBe(array_keys($ukrainian['budget']['options']))
        ->and($english['size']['options']['XL'])->toBe('XL');
});

it('sends the loader steps in the visitor language', function () {
    $generation = Generation::factory()->create(['with_background' => false, 'status' => 'processing']);
    $url = URL::temporarySignedRoute('fitting-room.generation.status', now()->addHour(), ['generation' => $generation->id]);

    expect($this->withHeader('Accept-Language', 'en')->getJson($url)->json('loader_steps'))
        ->toBe(['Analysing the photo', 'Trying the clothes on with AI', 'Creating the result']);
});
