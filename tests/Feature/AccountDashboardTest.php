<?php

/*
 * Account dashboard, built from the Figma "Desk_lk" frame: the try-on
 * workspace (photo / result / catalog), the share strip, "buy this look",
 * the consultation block, the lead cards and the plan grid. The balance, the
 * catalog and the try-on are real: the workspace runs on the same endpoints
 * as the internal page, with the member's own lead and token.
 */

use App\Models\Generation;
use App\Models\Lead;
use App\Models\Product;
use App\Models\User;
use App\Services\Generation\LeadToken;
use Illuminate\Support\Js;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['features.membership' => true]);
});

/** A signed-in member, the way phone signup creates them. */
function dashboardAsMember(): TestResponse
{
    return test()->actingAs(User::factory()->emailOnly()->onboarded()->create())->get('/account');
}

it('fits the phone screen from the first paint, before Alpine loads', function () {
    // Alpine only toggles the class later: without it in the markup the
    // catalog cards flashed at the pictures' full size on load.
    dashboardAsMember()->assertOk()->assertSee('<html lang="uk" dir="ltr" class="m-fit">', escape: false);

    test()->actingAs(User::factory()->emailOnly()->onboarded()->create())->get('/account/wardrobe')
        ->assertOk()->assertSee('<html lang="uk" dir="ltr" class="">', escape: false);
});

it('renders every section of the frame', function () {
    dashboardAsMember()
        ->assertOk()
        ->assertSee('id="tryon-section"', escape: false)
        ->assertSee('id="photo-panel"', escape: false)
        ->assertSee('id="result-panel"', escape: false)
        ->assertSee('id="catalog-panel"', escape: false)
        ->assertSee('id="share-section"', escape: false)
        ->assertSee('id="shop-section"', escape: false)
        ->assertSee('id="consult-section"', escape: false)
        ->assertSee('id="cta-cards"', escape: false)
        ->assertSee('id="plans-section"', escape: false);
});

it('carries the frame copy for the workspace panels, under the page head of Figma 1893:9423', function () {
    dashboardAsMember()
        ->assertSeeInOrder([
            'id="tryon-head"',
            'Створи свій образ',
            '<h1>Приміряти образ</h1>',
            'Обери одяг з каталогу та створи ідеальний образ за допомогою AI',
            'id="photo-panel"',
        ], escape: false)
        // The frame's own-garment button waits for an own-garment upload.
        ->assertDontSee('Завантажити власний образ')
        ->assertSee('Ваше фото')
        ->assertSee('Результат')
        ->assertSee('Обрати одяг')
        ->assertSee('Пошук по назві бренду чи категорії')
        ->assertSee('Обрати магазин / бренд одягу');
});

it('keeps the photo retention line honest — 72 hours, never "right after processing"', function () {
    // The frame says the photo is "deleted automatically after processing";
    // the provider keeps its copy for up to 72 hours, so the page says that.
    $page = dashboardAsMember()->assertSee('видаляються протягом 72 годин');

    foreach (['після обробки', 'одразу', 'миттєво', 'immediately', 'instantly'] as $forbidden) {
        $page->assertDontSee($forbidden);
    }
});

it('carries the LK_mob variants: dropzone, generate button, plan tabs', function () {
    // Both frames are in one document; CSS shows the one for the viewport.
    dashboardAsMember()
        ->assertSee('id="photo-dropzone"', escape: false)
        ->assertSee('Перетягнути файл або вибрати на пристрої')
        ->assertSee('Підтримуються JPG, PNG, WebP до 12 МБ')
        ->assertSee('Ваш образ з\'явиться тут')
        ->assertSee('id="generate-btn"', escape: false)
        ->assertSee('Згенерувати образ')
        ->assertDontSee('ще не підключена')
        ->assertSee('id="plan-tabs"', escape: false)
        ->assertSee('id="account-topbar"', escape: false);
});

it('carries the Main frame additions: replace photo, photo tips, card heart and pick button', function () {
    Product::factory()->verified()->create();

    dashboardAsMember()
        ->assertSee('id="replace-photo"', escape: false)
        ->assertSee('Замінити фото')
        ->assertSee('id="photo-tips"', escape: false)
        ->assertSeeInOrder(['Повний зріст', 'Хороше освітлення', 'Фігура добре видно'])
        ->assertSee('class="like"', escape: false)
        ->assertSee('class="pick"', escape: false)
        ->assertSee('>Обрати</button>', escape: false);
});

it('keeps the dropzone mark round when the phone frame is short', function () {
    // A flex child: without flex: none the 251px photo dropzone squeezed the
    // circle to 32×20 on a phone.
    $view = file_get_contents(resource_path('views/membership/dashboard.blade.php'));

    expect($view)->toContain('.dropzone .mark { flex: none; width: 32px; height: 32px; border-radius: 50%;');
});

it('opens the mobile plan switcher on the member\'s current plan', function () {
    dashboardAsMember()->assertSee('planTab: "shopaholic"', escape: false);

    test()->actingAs(User::factory()->emailOnly()->onboarded()->create())
        ->get('/account?plan=vip')->assertSee('planTab: "vip"', escape: false);
});

it('names the dashboard "AI-FittingRoom" in the navigation', function () {
    dashboardAsMember()->assertSee('AI-FittingRoom')->assertDontSee('About AI-FittingRoom');
});

/*
 * --- catalog panel ---
 */

it('builds the category chips from the feed categories of the verified products', function () {
    Product::factory()->verified()->ofFeedCategory('Юбки')->create();
    Product::factory()->verified()->ofFeedCategory('Платья')->create();
    Product::factory()->verified()->ofFeedCategory('Dresses')->create();
    Product::factory()->verified()->ofFeedCategory('Спорт')->create();
    // Unverified products contribute no chip; neither does a category nobody is in.
    Product::factory()->unverified()->ofFeedCategory('Брюки и джинсы')->create();

    dashboardAsMember()
        ->assertSee('id="catalog-categories"', escape: false)
        // "Усі" first, then the configured order; two feeds' words → one chip.
        ->assertSeeInOrder(['Усі', 'Сукні', 'Спідниці', 'Спорт'])
        ->assertDontSee('Штани та джинси')
        ->assertDontSee('Корсети');
});

it('tags every card with its chip so the chips actually filter', function () {
    Product::factory()->verified()->ofFeedCategory('Платья')->create(['product_name' => 'Silk Maxi']);

    dashboardAsMember()
        ->assertSee('data-category="Сукні"', escape: false)
        // matches(brand, name, category): the chip travels with the card.
        ->assertSee("'Silk Maxi', '", escape: false);
});

it('draws no chips at all while nothing is verified', function () {
    Product::factory()->unverified()->ofFeedCategory('Платья')->create();

    dashboardAsMember()
        ->assertSee('id="catalog-categories"', escape: false)
        // No "Усі" chip on either screen ("Усі бренди" is the brand field).
        ->assertDontSee('>Усі</button>', escape: false);
});

it('offers only verified products, with brand, name and AED price', function () {
    Product::factory()->verified()->brand('Mango')->create(['product_name' => 'Linen Wrap Dress', 'price_aed' => '599.00']);
    Product::factory()->unverified()->brand('Zara')->create(['product_name' => 'Unverified Coat']);

    dashboardAsMember()
        ->assertSee('id="catalog-grid"', escape: false)
        ->assertSee('Linen Wrap Dress')
        ->assertSee('599.00 AED')
        ->assertSee('data-brand="Mango"', escape: false)
        ->assertDontSee('Unverified Coat')
        ->assertDontSee('id="catalog-empty"', escape: false);
});

it('shows the whole verified catalog, not a page of it', function () {
    // A few dozen pieces is what the search and the brand picker are for; a
    // page cut would hide products the picker still counts.
    Product::factory()->verified()->count(40)->create();
    $oldest = Product::orderBy('id')->first();

    dashboardAsMember()->assertSee($oldest->product_name);
});

it('fills the searchable multi-brand picker from the verified products only, with counts', function () {
    Product::factory()->verified()->brand('Massimo Dutti')->count(2)->create();
    Product::factory()->unverified()->brand('Ghost Brand')->create();

    dashboardAsMember()
        ->assertSee('id="brand-picker"', escape: false)
        ->assertSee('aria-multiselectable="true"', escape: false)
        ->assertSee('placeholder="Знайти бренд"', escape: false)
        // Brand → count of verified pieces, fed to the picker as JSON.
        ->assertSee('"Massimo Dutti":2', escape: false)
        ->assertDontSee('Ghost Brand');
});

it('shows an empty state while the catalog has nothing verified', function () {
    Product::factory()->unverified()->create();

    dashboardAsMember()
        ->assertSee('id="catalog-empty"', escape: false)
        ->assertSee('id="shop-empty"', escape: false);
});

/*
 * --- the try-on ---
 */

it('mints the member\'s owned lead and its token on the page, once', function () {
    $user = User::factory()->emailOnly()->onboarded()->create();

    $page = test()->actingAs($user)->get('/account')->assertOk();

    $lead = Lead::sole();
    expect($lead->user_id)->toBe($user->id)
        ->and($lead->contact)->toBe($user->email)
        ->and($lead->tags)->toContain('member');
    $page->assertSee((string) app(LeadToken::class)->make($lead), escape: false);

    test()->actingAs($user)->get('/account')->assertOk();
    expect(Lead::count())->toBe(1);
});

it('wires the generate button to the try-on endpoints, behind both consents', function () {
    dashboardAsMember()
        ->assertSee('id="generate-btn"', escape: false)
        ->assertSee(':disabled="! canGenerate()"', escape: false)
        // The route map travels as JSON, slashes escaped.
        ->assertSee(json_encode(route('fitting-room.photo.store')), escape: false)
        ->assertSee(json_encode(route('fitting-room.generate')), escape: false)
        ->assertSee('id="tryon-consent"', escape: false)
        // The consents sit right under the generate button, gone with a look on screen.
        ->assertSeeInOrder(['id="generate-btn"', 'id="tryon-consent" role="group" x-show="! result"', 'id="result-actions"'], escape: false)
        ->assertSee('id="photo-consent"', escape: false)
        ->assertSee('id="terms-accept"', escape: false)
        ->assertSee(config('consent.text'))
        ->assertSee('id="gen-working"', escape: false)
        ->assertSee('id="gen-failed"', escape: false)
        ->assertSee('consentVersion: '.json_encode(config('consent.version')), escape: false)
        ->assertSee('termsVersion: '.json_encode(config('terms.version')), escape: false);
});

it('swaps the generate button for save / buy / try another once a look exists', function () {
    dashboardAsMember()
        ->assertSee('id="result-actions"', escape: false)
        ->assertSeeInOrder(['id="save-look"', 'Зберегти образ', 'id="buy-look"', 'Купити в магазині', 'id="try-another"', 'Приміряти іншу річ'], escape: false)
        ->assertSee(':href="downloadUrl()" download', escape: false)
        ->assertSee(':href="shopUrl()" target="_blank"', escape: false)
        ->assertSee('x-on:click="tryAnother()"', escape: false)
        // The result's buttons are route templates, filled in client-side.
        ->assertSee(json_encode(route('membership.account.looks.download', ['generation' => 0])), escape: false)
        ->assertSee(json_encode(route('membership.account.shop', ['product' => 0, 'from' => 'tryon'])), escape: false)
        ->assertDontSee('Згенерувати повторно');
});

it('shows the try-on loader as a checklist of the real pipeline, full-screen on a phone', function () {
    config(['fitting_room.loader.steps' => ['Аналізуємо фото', 'Приміряємо одяг за допомогою AI'], 'fitting_room.loader.final_step' => 'Створюємо результат']);

    dashboardAsMember()
        // In the result panel on desktop, teleported over the whole screen on a phone.
        ->assertSee('id="gen-working"', escape: false)
        ->assertSee('class="gen-loader only-desktop"', escape: false)
        ->assertSeeInOrder(['<template x-teleport="body">', 'class="gen-loader gen-loader-full only-mobile" id="gen-working-mobile"'], escape: false)
        ->assertSeeInOrder(['Створюємо твій образ…', 'Зазвичай це займає до хвилини', 'x-for="(step, i) in loaderSteps"', 'Порада', 'фото в повний зріст'], escape: false)
        // Seeded from config until the first poll sends this generation's own steps.
        ->assertSee('loaderSteps: '.Js::from(['Аналізуємо фото', 'Приміряємо одяг за допомогою AI', 'Створюємо результат']), escape: false)
        // Never the frame's "a few seconds": a look takes about a minute.
        ->assertDontSee('кілька секунд');
});

it('draws the card\'s pick button as an outline and fills it once the piece is picked', function () {
    $view = file_get_contents(resource_path('views/membership/dashboard.blade.php'));

    expect($view)->toContain('background: transparent; color: var(--ink-2); cursor: pointer;')
        ->and($view)->toContain('.pick-card.selected .pick { background: var(--ink-2); color: var(--surface); }');
});

it('puts the look\'s piece in the catalog\'s place on desktop and tablet once a look exists (Figma 1893:9143)', function () {
    dashboardAsMember()
        ->assertSee('id="chosen-panel" x-show="result && chosen()"', escape: false)
        ->assertSee(':class="{ \'has-look\': result && chosen(), \'has-result\': result }"', escape: false)
        ->assertSeeInOrder(['Обрана річ', 'Додати в обране', 'id="chosen-buy"', 'Перейти до магазину', 'id="save-to-wardrobe"', 'Зберегти в гардероб', 'id="chosen-try-another"', 'Приміряти іншу річ'], escape: false)
        // The look already lives in the wardrobe: the button leads there.
        ->assertSee('id="save-to-wardrobe" href="'.route('membership.account.wardrobe').'"', escape: false)
        // The phone's four tiles, then the feed's description folded away.
        ->assertSeeInOrder([
            'id="chosen-tools"',
            'id="chosen-save" href="'.route('membership.account.wardrobe').'"', 'Зберегти',
            'id="chosen-compare" x-on:click="toggleCompare()"', 'Порівняти',
            'id="chosen-share" x-on:click="shareLook()"', 'Поділитися',
            'id="chosen-download" :href="downloadUrl()" download', 'Завантажити',
            'Деталі товару', 'x-text="chosen()?.description"',
        ], escape: false)
        // No size / colour line: the feeds carry neither.
        ->assertDontSee('Розмір:');

    // From 834px: beside the result from 1200px, the full row under it on a tablet.
    $view = file_get_contents(resource_path('views/membership/dashboard.blade.php'));
    expect($view)->toContain('.chosen-panel { display: none; }')
        ->and($view)->toContain("@media (min-width: 834px) {\n            .chosen-panel { display: block; }\n            .tryon-grid.has-look #catalog-panel, .tryon-grid.has-look #result-actions { display: none; }")
        ->and($view)->toContain('@media (min-width: 834px) and (max-width: 1199px) { .chosen-panel { grid-column: 1 / -1; } }');
});

it('generates the look on screen once more from under the result', function () {
    $page = dashboardAsMember()
        ->assertSeeInOrder(['id="result-panel"', 'id="regenerate-btn"', 'x-show="result"', ':disabled="! canRegenerate()" x-on:click="regenerate()"', 'Згенерувати ще раз'], escape: false);

    // The same piece back in the pick, then the ordinary (credited) try-on.
    expect($page->getContent())->toMatch('/regenerate\(\) \{\s+if \(! this\.canRegenerate\(\)\) return;\s+this\.selected = this\.resultProduct;\s+this\.generate\(\);/');
});

it('makes the desktop compare button lead somewhere instead of opening the phone-only tray', function () {
    // Bug: with one look "Порівняти" only set trayOpen, and the tray exists on
    // the phone alone, so on a desktop the click did nothing at all.
    dashboardAsMember()->assertSeeInOrder([
        'toggleCompare() {',
        'if (this.looks.length < 2) {',
        'this.trayOpen = true;',
        'if (! this.phone) {',
        "this.scrollTo('compare-strip');",
        "document.getElementById('add-look')?.focus({ preventScroll: true });",
        'this.comparing = true;',
        "if (! this.phone) this.scrollTo('result-panel');",
    ], escape: false);
});

it('lays out step 3 below the workspace: compare looks, pieces that go with it, the stylist', function () {
    config(['membership.stylist_booking_url' => 'https://book.test/stylist']);

    $page = dashboardAsMember()
        ->assertSeeInOrder([
            'id="look-extras" x-show="result"',
            'id="compare-strip"', 'Порівняти образи', 'x-text="looks.length"', 'x-text="MAX_LOOKS"',
            'x-for="(look, i) in looks"', 'x-on:click="removeLook(look.id)"',
            'id="add-look" x-show="looks.length < MAX_LOOKS" x-on:click="tryAnother()"', 'Додати образ',
            'id="compare-go" x-on:click="toggleCompare()" :disabled="looks.length < 2"',
            'id="look-pairs" x-show="resultPairs().length"', 'x-for="p in resultPairs()"', 'Купити', 'x-on:click="toggleLike(p.id)"',
            'id="ask-strip"', 'Потрібна думка стиліста?', 'href="https://book.test/stylist"', 'Запитати стилістку',
        ], escape: false);

    // The pieces are the catalog's own pairings for the look's piece, four at most.
    expect($page->getContent())->toContain('return piece ? piece.pairs.map((id) => this.details[id]).filter(Boolean).slice(0, 4) : [];');
});

it('keeps the desktop stylist card with an inert button when no booking link is set', function () {
    config(['membership.stylist_booking_url' => null]);

    dashboardAsMember()
        ->assertSeeInOrder(['id="ask-strip"', 'Потрібна думка стиліста?', '<button type="button" class="dl-btn dl-btn-dark" disabled>Запитати стилістку</button>'], escape: false);
});

/*
 * --- buy this look ---
 */

it('shows the stylist\'s photo in the consultation block', function () {
    expect(file_exists(public_path('images/consultation.jpg')))->toBeTrue();

    dashboardAsMember()
        ->assertSee('<img src="'.asset('images/consultation.jpg').'" alt="Стилістка Atelier Fit">', escape: false);
});

it('reads the buy button brand from the product row and counts the click on the way to its shop', function () {
    $product = Product::factory()->verified()->brand('Ounass')->create([
        'product_name' => 'Silk Slip Dress',
        'shop_url' => 'https://shop.example/ounass/silk-slip-dress',
    ]);

    // The button goes through the click counter, which redirects to the row's
    // own shop_url (ShopRedirectTest).
    dashboardAsMember()
        ->assertSee('Придбати образ')
        ->assertSee('Перейти до магазину')
        ->assertSee('href="'.route('membership.account.shop', ['product' => $product->id, 'from' => 'catalog']).'"', escape: false)
        ->assertSee('Ounass');
});

it('hardcodes no shop in the template', function () {
    $view = file_get_contents(resource_path('views/membership/dashboard.blade.php'));

    expect($view)->not->toContain('namshi.com')
        ->and($view)->not->toContain('ounass.com');
});

it('lets the shop cards and share strip shrink to the content area instead of overflowing it', function () {
    $view = file_get_contents(resource_path('views/membership/dashboard.blade.php'));

    // 3 x 378px only fits a 1920px screen; at 1280px the content area next
    // to the sidebar is ~944px, so fixed tracks pushed the cards past it.
    expect($view)->not->toMatch('/\.shop-grid \{[^}]*repeat\(3, 378px\)/')
        ->and($view)->toContain('.shop-grid { grid-template-columns: repeat(3, minmax(0, 378px)); }')
        // Heading + seven share buttons no longer fit one row at 1280px:
        // the row must be allowed to wrap rather than squeeze.
        ->and($view)->toMatch('/\.share-strip \{ flex-direction: row; flex-wrap: wrap;/');
});

it('moves the page actions under the heading rather than stacking them when the row is too narrow', function () {
    $layout = file_get_contents(resource_path('views/membership/layout.blade.php'));

    // Without wrap, at 1280px the heading squeezed .dl-actions until its two
    // buttons wrapped into a column.
    expect($layout)->toContain('@media (min-width: 1280px) { .dl-head { flex-direction: row; flex-wrap: wrap;');
});

it('keeps the lead cards\' field and button in one row on desktop, and the forms level', function () {
    $view = file_get_contents(resource_path('views/membership/dashboard.blade.php'));

    // One row on every desktop width: the narrow 1280–1919 cards set the
    // button tighter instead of stacking it. Nothing stacks it: the phone
    // layout no longer shows the cards at all.
    expect($view)->toContain('.cta-form { margin-top: auto; display: flex; gap: 7px; }')
        ->and(substr_count($view, '.cta-form { flex-direction: column;'))->toBe(0)
        ->and($view)->toContain('@media (min-width: 1280px) and (max-width: 1919px) {'."\n".'            .cta-card .cta-form .dl-btn { padding-inline-start: 14px;')
        // The middle card's button matches them there: the 16px label wrapped
        // to two lines and stood taller than the forms beside it.
        ->and($view)->toContain('.cta-card > .dl-btn { padding-inline-start: 14px; padding-inline-end: 14px; font-size: 13px; letter-spacing: .06em; white-space: nowrap; }')
        // Equal cards: plain 1fr let one card widen and push another's button out.
        ->and($view)->toContain('@media (min-width: 1280px) { .cta-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }')
        // The text takes the slack and the notes reserve two lines, so every
        // form (and the middle card's button) sits on one line at the foot.
        ->and($view)->toContain('.cta-card .text { flex: 1 0 auto;')
        ->and($view)->toContain('.cta-card > .dl-btn { margin-top: auto; }')
        ->and($view)->toContain('@media (min-width: 1280px) { .cta-card .note { min-height: 3em; } }');
});

it('puts all five plans in one row only from 1920px', function () {
    $view = file_get_contents(resource_path('views/membership/dashboard.blade.php'));

    // Five cards at 1280px needed ~1416px next to the sidebar's ~944px and
    // pushed the last plan off the page.
    expect($view)->toContain('@media (min-width: 1280px) { .plans-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }')
        ->and($view)->toContain('@media (min-width: 1920px) { .plans-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); } }')
        ->and(preg_match_all('/\.plans-grid \{ grid-template-columns: repeat\(5/', $view))->toBe(1);
});

it('lays the catalog out by the room it has: full width on a tablet, two then three abreast in the column', function () {
    $view = file_get_contents(resource_path('views/membership/dashboard.blade.php'));

    expect($view)->toContain('.pick-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));')
        // Tablet: under photo and result, as many ~150px cards as fit.
        ->and($view)->toContain("@media (min-width: 834px) and (max-width: 1199px) {\n            #catalog-panel { grid-column: 1 / -1; }")
        ->and($view)->toContain('.pick-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));')
        // Three columns from 1200px; three cards a row only from 1900px — at
        // 1600 three made 146px cards with a 34px picture.
        ->and($view)->toContain('@media (min-width: 1200px) { .tryon-grid { grid-template-columns: minmax(0, 378fr) minmax(0, 512fr) minmax(0, 646fr); } }')
        ->and($view)->toContain('@media (min-width: 1900px) { #catalog-panel .pick-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); } }')
        ->and($view)->not->toContain('@media (min-width: 1600px) { #catalog-panel .pick-grid')
        // The catalog column is as tall as its neighbours and scrolls inside.
        ->and($view)->toContain('#catalog-panel { display: flex; flex-direction: column; contain: size; min-height: 640px; }');
});

it('keeps a catalog card\'s name on one line, cut with an ellipsis', function () {
    $view = file_get_contents(resource_path('views/membership/dashboard.blade.php'));

    expect($view)->toContain(".pick-card .name { font-family: var(--serif); font-size: 12.7px; line-height: 1.3; color: var(--ink-2); margin: 5px 0;\n            overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }")
        // Only the phone's product card (Figma 2093:9429) lets a name wrap to two lines.
        ->and(preg_replace('/\.pm-name \{[^}]*\}/', '', $view))->not->toContain('-webkit-line-clamp: 2');
});

it('puts the generate button in the middle of the empty result, as the frame draws it', function () {
    dashboardAsMember()
        ->assertSeeInOrder([
            'id="result-panel"',
            'Ваш образ з\'явиться тут',
            'id="generate-btn"',
            'class="generate-note only-desktop"',
            'id="zoom-bar"',
        ], escape: false);
});

it('zooms a finished look 100–200% and opens it full screen', function () {
    $page = dashboardAsMember()
        ->assertSee('id="zoom-bar" x-show="result"', escape: false)
        ->assertSee('aria-label="Зменшити"', escape: false)
        ->assertSee('aria-label="Збільшити"', escape: false)
        ->assertSee('aria-label="На весь екран"', escape: false);

    expect($page->getContent())
        ->toContain("'--split: ' + split + '%; --zoom: ' + zoom")
        ->toContain('ZOOM_MIN: 1,')
        ->toContain('ZOOM_MAX: 2,')
        ->toContain('#result-panel .compare { transform: scale(var(--zoom, 1));')
        // A new look starts at 100%.
        ->toMatch('/resetResult\(\) \{\s+this\.stopLoader\(\);\s+this\.zoom = 1;/');
});

it('draws the catalog card as the frame does: ring to pick, heart top-left', function () {
    $view = file_get_contents(resource_path('views/membership/dashboard.blade.php'));

    expect($view)->toContain('.pick-card { aspect-ratio: 197 / 237; border-radius: 14px; padding: 34px 9px 18px; }')
        ->and($view)->toContain('.pick-card .pick { position: absolute; top: 12px; inset-inline-end: 16px; width: 22px; height: 22px;')
        ->and($view)->toContain('.pick-card .like { top: 10px; inset-inline-start: 12px; inset-inline-end: auto;');
});

/*
 * --- share strip, consultation, lead cards ---
 */

it('draws the share strip with one button per network, inert until a look exists', function () {
    dashboardAsMember()
        ->assertSee('Сподобався образ?')
        ->assertSee('Збережи його та поділись з друзями')
        ->assertSee('aria-label="Поділитись у Instagram"', escape: false)
        ->assertSee('aria-label="Поділитись у Threads"', escape: false)
        ->assertSee(':disabled="! result"', escape: false);
});

it('draws every share network with the frame\'s icon, coloured by the button', function () {
    $networks = ['instagram', 'tiktok', 'facebook', 'linkedin', 'pinterest', 'youtube', 'threads'];

    foreach ($networks as $network) {
        $svg = view('membership.partials.share-icon', ['network' => $network])->render();

        // The Figma exports (frame 1661:6608) at their placed size, stroked in
        // currentColor so the disabled and hover states reach them.
        expect($svg)->toContain('<svg ')
            ->and($svg)->toContain('stroke="currentColor"')
            ->and($svg)->not->toMatch('/stroke="#/')
            ->and($svg)->toContain('aria-hidden="true"');
    }

    // Instagram fills the 52px button as the 38.8px component does in the frame.
    expect(view('membership.partials.share-icon', ['network' => 'instagram'])->render())
        ->toContain('width="52" height="52" viewBox="0 0 38.7975 38.7975"');
});

it('renders the consultation buttons inert until a booking link is configured', function () {
    config(['membership.stylist_booking_url' => null]);

    dashboardAsMember()
        ->assertSee('Підібрати образ зі стилісткою')
        ->assertSee('Забронювати експрес-консультацію зі стилісткою')
        ->assertSee('Хочу консультацію зі стилісткою')
        ->assertSee('disabled title="Бронювання відкриється незабаром"', escape: false);
});

it('links the consultation buttons to the configured booking page', function () {
    config(['membership.stylist_booking_url' => 'https://book.example/stylist']);

    dashboardAsMember()
        ->assertSee('href="https://book.example/stylist"', escape: false)
        ->assertDontSee('Бронювання відкриється незабаром');
});

it('draws the three lead cards without pretending a mailing list exists', function () {
    dashboardAsMember()
        ->assertSee('Не пропускай нові образи та fashion-знахідки')
        ->assertSee('Готові приміряти новий стиль у реальному житті?')
        ->assertSee('Відкрийте свій AI-лукбук у високій якості')
        ->assertSee('placeholder="Email або WhatsApp:"', escape: false)
        ->assertSee('Підписатися')
        ->assertSee('Надіслати')
        // Submitting says the list is not live yet, instead of posting anywhere.
        ->assertSee("comingSoon('subscribe')", escape: false)
        ->assertSee("comingSoon('lookbook')", escape: false)
        ->assertSee('Розсилка ще не запущена');
});

/*
 * --- plans ---
 */

it('shows the five plan cards as drawn, marked as not yet purchasable', function () {
    dashboardAsMember()
        ->assertSee('Приміряй більше. Купуй впевненіше.')
        ->assertSeeInOrder(['FREE', 'Fashionista', 'Shopaholic', 'Couture', 'Diva'])
        ->assertSee('AED 0')
        ->assertSee('AED 39')
        ->assertSee('AED 99')
        ->assertSee('AED 269')
        ->assertSee('AED 749 / місяць')
        ->assertSee('id="plan-shopaholic"', escape: false)
        ->assertSee('Спробувати безкоштовно')
        ->assertSee('Обрати Diva VIP')
        ->assertSee(route('membership.pricing'), escape: false)
        ->assertSee('id="plans-note"', escape: false)
        ->assertSee('Тарифи поки не доступні для покупки');
});

/*
 * Phone: steps 1–3 are the "Примірка" screen (the design hand-over) in the site's
 * monochrome, one viewport between the shell's top bar and tabs.
 */

it('gives the phone the try-on screen in place of the old steps', function () {
    dashboardAsMember()
        ->assertSeeInOrder([
            'id="tryon-section"',
            'id="m-tryon"',
            'images/tryon/hero-model.png', 'AI Try-On', 'Побач, як виглядає одяг<br>на тобі до покупки.',
            'Повний образ', 'Якісне освітлення', 'Твоя фігура видима',
            'Крок 1 · Додай фото', 'Додати фото', 'JPG, PNG, WebP до 12 МБ',
            'Крок 2 · Обери одяг', 'Пошук за брендом або товаром', 'Усі бренди',
            'id="m-tryon-grid"',
            'id="m-tryon-generate"', '<span class="mt-num mt-num-3" aria-hidden="true">3</span>', 'Згенерувати образ',
            'class="tryon-grid"',
        ], escape: false)
        ->assertDontSee('id="m-upload"', escape: false)
        ->assertDontSee('id="generate-btn-mobile"', escape: false)
        ->assertDontSee('Крок 2 — Обери речі')
        ->assertDontSee("scrollMeter('catalog-grid')", escape: false);
});

it('raises the step 2 pill under the model\'s feet until the photo is in', function () {
    dashboardAsMember()
        ->assertSee('<div class="mt-step2 is-raised" :class="{ \'is-raised\': ! photoUrl }">', escape: false);

    $view = file_get_contents(resource_path('views/membership/dashboard.blade.php'));
    // The pill keeps to the left, clear of the upload card, and lets taps through.
    expect($view)->toContain('.mt-step2.is-raised { margin-top: -30px; position: relative; align-self: flex-start; pointer-events: none; }')
        ->and($view)->toContain('.mt-step2.is-raised { margin-top: -22px; }')
        // The upload card reaches down level with the pill: 12px, 20px on short phones.
        ->and($view)->toContain('.mt-top .mt-step1 { margin-bottom: -12px; }')
        ->and($view)->toContain('.mt-top .mt-step1 { margin-bottom: -20px; }');
});

it('folds step 1 into a strip once the photo is in, step 2 keeps its pill', function () {
    dashboardAsMember()
        ->assertSee('<section class="mt-top" x-show="! photoUrl">', escape: false)
        ->assertSeeInOrder([
            '<section class="mt-strip" id="m-tryon-strip" x-show="photoUrl" x-cloak>',
            'id="m-tryon-photo" :src="photoUrl"', 'Крок 1', 'Фото додано',
            'id="m-tryon-change" x-on:click="$refs.photo.value = \'\'; $refs.photo.click()"', 'Змінити',
            '<p class="mt-pill2"><span class="mt-num mt-num-2">2</span><span class="mt-pill-text">Крок 2 · Обери одяг</span></p>',
            'class="mt-search-row"', 'id="m-tryon-search"',
        ], escape: false);
});

it('runs the phone screen on the dashboard\'s own state', function () {
    $product = Product::factory()->verified()->create(['brand_name' => 'ZARA', 'product_name' => 'Сукня міді', 'price_aed' => 199]);

    dashboardAsMember()
        // The photo: the dashboard's file input, drop and state.
        ->assertSee('id="m-tryon-upload" x-on:click="$refs.photo.value = \'\'; $refs.photo.click()"', escape: false)
        ->assertSee('x-on:drop.prevent="dropPhoto($event)"', escape: false)
        ->assertSee('id="m-tryon-photo" :src="photoUrl"', escape: false)
        ->assertSeeInOrder(['images/lk-mob/photo-added.svg', "photoUrl ? 'Фото додано' : 'Додати фото'"], escape: false)
        // The catalog: its filters and pick.
        ->assertSee('x-model.debounce.150ms="query"', escape: false)
        ->assertSee('x-on:click="brands = [b]; brandOpen = false"', escape: false)
        ->assertSee("x-show=\"matches('ZARA', ", escape: false)
        ->assertSee("x-on:click=\"toggle({$product->id})\"", escape: false)
        ->assertSee("x-on:click=\"openDetail({$product->id})\"", escape: false)
        ->assertSee("x-on:click=\"toggleLike({$product->id})\"", escape: false)
        ->assertSeeInOrder(['ZARA', 'Сукня міді', 'AED 199', 'Приміряти'])
        // The try-on: the dashboard's, with its loader and result screen.
        ->assertSee('id="m-tryon-generate" :disabled="! canGenerate()" x-on:click="generate()"', escape: false)
        ->assertSee('id="m-tryon-status" aria-live="polite" x-show="genState === \'failed\'" x-cloak x-text="genMessage"', escape: false)
        ->assertSee('id="m-tryon-consent"', escape: false)
        ->assertSee('<label><input type="checkbox" x-model="consent">', escape: false)
        ->assertSee('<label><input type="checkbox" x-model="terms">', escape: false)
        // Steps aside for the finished look.
        ->assertSee('id="m-tryon" x-show="! result"', escape: false);
});

it('offers "Для тебе" only with a season to go by', function () {
    dashboardAsMember()->assertDontSee('Для тебе');
});

it('fits the phone screen into one viewport while no look is on screen', function () {
    dashboardAsMember()->assertSee("x-effect=\"document.documentElement.classList.toggle('m-fit', ! result)\"", escape: false);

    $view = file_get_contents(resource_path('views/membership/dashboard.blade.php'));
    expect($view)
        ->toContain('html.m-fit .acct-shell { height: 100vh; height: 100dvh; min-height: 0; flex-direction: column; flex-wrap: nowrap; overflow: hidden; }')
        ->toContain('html.m-fit .acct-tabs { position: static; border-top-color: var(--border); }')
        ->toContain('.mt > .mt-grid { flex: 1 1 auto; min-height: 0; }')
        ->toContain('.mt-card-pic { position: relative; flex: 1; min-height: 0;')
        // The piece sits on white, not on the grey second surface.
        ->toContain('.mt-card-pic { position: relative; flex: 1; min-height: 0; border-radius: 10px; overflow: hidden; background: var(--surface); }')
        ->toContain('.mt-top { display: flex; gap: 8px; padding: 0 12px 0 0; height: 240px;')
        ->toContain('@media (max-width: 833px) and (max-height: 700px)')
        ->toContain('.mt-tips { display: none; }')
        // The desktop's panels are not on the phone; the result panel is, once there is a look.
        ->toContain('#photo-panel, #catalog-panel { display: none; }');
});

it('paints the phone screen in the site\'s monochrome', function () {
    $partial = file_get_contents(resource_path('views/membership/partials/m-tryon.blade.php'));
    $view = file_get_contents(resource_path('views/membership/dashboard.blade.php'));

    preg_match_all('/#[0-9A-Fa-f]{6}\b/', $partial, $hex);
    expect(array_unique(array_map('strtoupper', $hex[0])))->toBe(['#F0F0F0'])
        // The palette table of the design hand-over: the site's four, and two greys for the lines.
        ->and($view)->toContain('html.m-fit { --border: #E6E6E6; --dashed: #BDBDBD; }')
        ->and($view)->toContain('border: 1.5px dashed var(--dashed);')
        ->and($view)->toContain('html.m-fit .acct-tabs { position: static; border-top-color: var(--border); }')
        ->and($view)->toContain('.mt-lede { margin: 0; font-size: 10.5px; line-height: 1.35; white-space: nowrap; color: var(--muted); }')
        // Step numbers are dark circles with a white digit, the one in the button too.
        ->and($view)->toContain('.mt-num-3 { position: absolute; inset-inline-start: 8px; top: 8px; width: 28px; height: 28px; background: var(--ink); color: var(--surface);')
        // No cream, brown or gold left.
        ->and($view)->not->toContain('#F4EFE7')
        ->and($view)->not->toContain('#FBF8F3')
        ->and($view)->not->toContain('#5B3F2E')
        ->and($view)->not->toContain('#C8A97C')
        ->and($view)->toContain('.mt-go { width: 100%; height: 44px; border-radius: 22px; border: 0; background: var(--ink); color: var(--surface);');
});

it('keeps the empty result panel off the phone until there is a look', function () {
    dashboardAsMember()
        ->assertSee(<<<'HTML'
            id="result-panel" :class="{ 'm-idle': ! result && genState === 'idle' }"
            HTML, escape: false);
});

it('gives the phone its own finished-look screen', function () {
    config(['membership.stylist_booking_url' => 'https://book.test/stylist']);

    dashboardAsMember()
        ->assertSee("'has-result': result", escape: false)
        ->assertSeeInOrder([
            'id="m-result-step"', 'Крок 3 — Твій образ готовий',
            'Результат примірки',
            'id="m-result"',
            // Save takes the member to the wardrobe, where the look already is.
            'id="m-save" href="'.route('membership.account.wardrobe').'"',
            'id="m-compare" x-on:click="toggleCompare()"',
            'id="m-share" x-on:click="shareLook()"',
            'id="m-download" :href="downloadUrl()" download',
            'id="m-item"',
            'id="m-try-another" x-on:click="tryAnother()"',
            'id="m-shop" :href="shopUrl()"',
            'id="m-ask"', 'https://book.test/stylist',
        ], escape: false);
});

it('frames the phone before/after upright, since phone photos are vertical', function () {
    dashboardAsMember()
        ->assertSee('.tryon-grid.has-result #result-panel .frame-2x3 { aspect-ratio: 3 / 4;', escape: false)
        ->assertDontSee('aspect-ratio: 336 / 236', escape: false);
});

it('gives the search and the brand field one height', function () {
    $view = file_get_contents(resource_path('views/membership/dashboard.blade.php'));

    // The box sets the height for both; the search input brings no padding of
    // its own (browsers give it 1px, which made it 1.6px taller than the brand).
    expect($view)->toContain('.catalog-field { display: flex; align-items: center; justify-content: space-between; gap: 10px; min-height: 43px;')
        ->and($view)->toContain('margin: 0; padding: 0; font-family: \'Montserrat\', sans-serif; font-weight: 300; font-size: 16px;')
        ->and($view)->not->toMatch('/\.brand-trigger \{[^}]*min-height/');
});

it('puts no hint under the phone\'s generate button; desktop keeps its own', function () {
    dashboardAsMember()
        ->assertDontSee('class="generate-note only-mobile"', escape: false)
        ->assertSee('class="generate-note only-desktop"', escape: false);
});

it('ends the page at the try-on on every screen, the share strip and all below it hidden', function () {
    $view = file_get_contents(resource_path('views/membership/dashboard.blade.php'));
    $rule = '#share-section, #shop-section, #consult-section, #cta-cards, #plans-section { display: none; }';

    // Once, and outside any media query: phone, tablet and desktop alike.
    expect(substr_count($view, $rule))->toBe(1);
    $before = substr($view, 0, strpos($view, $rule));
    expect(substr_count($before, '{') - substr_count($before, '}'))->toBe(0);

    dashboardAsMember()
        ->assertSee($rule, escape: false)
        ->assertSee('id="share-section"', escape: false)
        ->assertSee('id="plans-section"', escape: false);
});

it('shrinks the phone before/after to half on scroll with the content rising under it', function () {
    $page = dashboardAsMember()
        ->assertSeeInOrder([
            'class="m-shrink-mark"',
            'class="m-shrink" x-data="shrinkFrame()"',
            '<div class="frame-2x3">',
            'class="compare"',
        ], escape: false)
        ->assertSee('function shrinkFrame()', escape: false)
        // Carried down by the scroll, not stuck over what follows.
        ->assertSee('transform: translateY(var(--shrink-y, 0px)) scale(var(--shrink-s, 1));', escape: false)
        // Half its size at most, never smaller.
        ->assertSee('const scale = 1 - shift / height / 2;', escape: false)
        // The box holds the shift plus the shrunk frame, so the content sits right under it.
        ->assertSee("(shift + height * scale).toFixed(1) + 'px'", escape: false)
        ->assertSee('html:has(.tryon-grid.has-result) { overflow-anchor: none; }', escape: false);

    // A sticky frame is what slid the content under the picture.
    expect($page->getContent())->not->toContain('.m-shrink { position: sticky');
});

it('lets the phone compare the looks made on the same photo', function () {
    dashboardAsMember()
        ->assertSeeInOrder([
            // Comparing puts two looks where before and after were.
            'compareLook(0)?.url : (beforeUrl || photoUrl)',
            'compareLook(1)?.url : result',
            // The tray: count, the looks, add one more, compare.
            'id="m-compare-tray"',
            'Образи', 'x-text="looks.length"', 'x-text="MAX_LOOKS"',
            'x-for="(look, i) in looks"', 'removeLook(look.id)',
            'id="m-add-look"', 'x-on:click="tryAnother()"',
            'id="m-compare-go" x-on:click="toggleCompare()"',
        ], escape: false)
        // Every finished look joins this photo's set, three at most...
        ->assertSee('if (this.result) this.addLook(', escape: false)
        ->assertSee('MAX_LOOKS: 3', escape: false)
        // ...and a new photo starts the set over.
        ->assertSeeInOrder(['usePhoto(file) {', 'this.looks = [];', 'resetResult() {'], escape: false);
});

it('keeps the stylist card with an inert button when no booking link is set', function () {
    config(['membership.stylist_booking_url' => null]);

    dashboardAsMember()
        ->assertSeeInOrder([
            'id="m-ask"',
            'Потрібна думка стиліста?',
            '<button type="button" class="m-btn m-btn-dark" disabled>Запитати стилістку</button>',
        ], escape: false);
});

it('shows the whole chosen piece next to the look, never a crop of its middle', function () {
    // Bug: object-fit: cover centred a long dress and cut off its hem and top.
    $view = file_get_contents(resource_path('views/membership/dashboard.blade.php'));

    expect($view)->toContain('.chosen-card .pic img { display: block; width: 100%; height: 100%; object-fit: contain; }')
        ->and($view)->not->toContain('.chosen-card .pic img { width: 100%; height: 100%; object-fit: cover; }');
});

it('shows the whole piece on the phone, never a crop of its middle', function () {
    $view = file_get_contents(resource_path('views/membership/dashboard.blade.php'));

    expect($view)->toContain('.mt-card-pic img { width: 100%; height: 100%; object-fit: contain; display: block; }')
        ->and($view)->toContain('.m-item-pic img { display: block; width: 100%; height: 100%; object-fit: contain; }');
});

describe('?debug_result', function () {
    /** A member with one finished look of their own and one of someone else's. */
    function memberWithDebugLook(): array
    {
        $user = User::factory()->emailOnly()->onboarded()->create();
        $mine = Generation::factory()->done()
            ->for(Lead::factory()->forUser($user))
            ->create(['result_urls' => [['url' => 'https://cdn.fashn.test/mine.png', 'with_background' => false]]]);
        $theirs = Generation::factory()->done()->create(['result_urls' => ['https://cdn.fashn.test/theirs.png']]);

        return [$user, $mine, $theirs];
    }

    it('opens step 3 on the member\'s latest look, locally', function () {
        app()->detectEnvironment(fn () => 'local');
        [$user, $mine] = memberWithDebugLook();

        $this->actingAs($user)->get('/account?debug_result')
            ->assertOk()
            ->assertViewHas('debugLook', fn ($look) => $look['id'] === $mine->id
                && $look['after'] === 'https://cdn.fashn.test/mine.png'
                && $look['product'] === $mine->product_id);
    });

    it('carries the photo key, so another look can be generated on the same photo', function () {
        // Bug: the debug look had a photo on screen but no file and no key, so
        // after "Додати образ" the generate button stayed disabled.
        app()->detectEnvironment(fn () => 'local');
        [$user, $mine] = memberWithDebugLook();

        $this->actingAs($user)->get('/account?debug_result')
            ->assertViewHas('debugLook', fn ($look) => $look['photo_key'] === $mine->client_photo)
            ->assertSeeInOrder([
                'hasPhoto() {', 'return !! (this.photoFile || this.photoKey);',
                'canGenerate(pick = this.selected) {', 'this.hasPhoto()',
                'canRegenerate() {', 'this.hasPhoto()',
                'showDebugLook(look) {', 'this.photoKey = look.photo_key;',
            ], escape: false);
    });

    it('never shows another member\'s look', function () {
        app()->detectEnvironment(fn () => 'local');
        [$user, , $theirs] = memberWithDebugLook();

        $this->actingAs($user)->get('/account?debug_result='.$theirs->id)
            ->assertViewHas('debugLook', null);
    });

    it('does nothing outside local', function () {
        app()->detectEnvironment(fn () => 'production');
        [$user] = memberWithDebugLook();

        $this->actingAs($user)->get('/account?debug_result')
            ->assertViewHas('debugLook', null);
    });
});
