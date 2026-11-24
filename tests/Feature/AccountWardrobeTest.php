<?php

/*
 * "Мій гардероб" (Figma frame 1456:10104): the member's finished try-ons
 * with the pieces they were made of. Real data — nothing is shown that the
 * member did not generate — and every shop button reads its brand and link
 * from the product row.
 */

use App\Models\Generation;
use App\Models\Lead;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['features.membership' => true]);
});

/** A member on a plan that has the wardrobe open, with a finished try-on. */
function memberWithLook(array $productAttributes = []): array
{
    $user = User::factory()->emailOnly()->create();
    $product = Product::factory()->verified()->create($productAttributes);
    $generation = Generation::factory()->done()->create([
        'lead_id' => Lead::factory()->forUser($user)->create()->id,
        'product_id' => $product->id,
    ]);

    return [$user, $product, $generation];
}

it('renders the frame head with the wardrobe / favourites tabs and category chips', function () {
    test()->actingAs(User::factory()->emailOnly()->create())
        ->get('/account/wardrobe?plan=couture')
        ->assertOk()
        ->assertSee('Мій гардероб')
        ->assertSee('Тут зберігатимуться образи, які ви створили під час віртуальної примірки.')
        ->assertSee('id="wardrobe-tabs"', escape: false)
        ->assertSee('Обране')
        ->assertSee('id="wardrobe-filter"', escape: false)
        ->assertSee('id="account-topline"', escape: false);
});

it('builds the category chips from the feed categories of the member\'s own pieces', function () {
    [$user] = memberWithLook(['feed_category' => 'Юбки', 'product_name' => 'Спідниця міді']);

    test()->actingAs($user)
        ->get('/account/wardrobe?plan=couture')
        ->assertOk()
        ->assertSeeInOrder(['Усі', 'Спідниці'])
        ->assertSee('data-category="Спідниці"', escape: false)
        ->assertDontSee('Сукні');
});

it('shows an empty state until the member has tried something on', function () {
    test()->actingAs(User::factory()->emailOnly()->create())
        ->get('/account/wardrobe?plan=couture')
        ->assertSee('id="wardrobe-empty"', escape: false)
        ->assertSee('У вашому гардеробі ще немає збережених образів')
        ->assertSee('Створіть першу AI-примірку, щоб зберегти образ тут')
        // Two ways into the try-on (Figma 1906:11347): at the photo, or at the catalog.
        ->assertSeeInOrder([
            'id="empty-start" href="'.route('membership.account.dashboard', ['plan' => 'couture']).'#photo-panel"', 'Почати AI-примірку',
            'id="empty-catalog" href="'.route('membership.account.dashboard', ['plan' => 'couture']).'#catalog-panel"', 'Перейти до AI-Fitting Room',
        ], escape: false)
        // Nothing to compare in an empty wardrobe.
        ->assertDontSee('id="compare-looks"', escape: false);
});

it('opens a set on a desktop as Figma 1954:9485: compare, four tiles, the piece with shop and try-another, the stylist', function () {
    [$user, $product, $generation] = memberWithLook([
        'brand_name' => 'OneByOne',
        'product_name' => 'Костюм кольору марсала',
        'price_aed' => '599.00',
        'external_id' => '00000040713',
        'shop_url' => 'https://shop.example/onebyone/suit',
    ]);

    test()->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertDontSee('id="wardrobe-empty"', escape: false)
        ->assertSee('id="look-card-'.$generation->id.'"', escape: false)
        ->assertSee('Переглянути')
        ->assertSee('id="look-'.$generation->id.'"', escape: false)
        ->assertSeeInOrder([
            'class="set-tiles only-desktop"', 'Зберегти', 'Завантажити',
            'id="piece-'.$generation->id.'-'.$product->id.'"', 'OneByOne', 'Костюм кольору марсала', '599.00 AED',
            'class="piece-actions"',
            'href="'.route('membership.account.shop', ['product' => $product->id, 'from' => 'wardrobe']).'"', 'Перейти до магазину',
            'href="'.route('membership.account.dashboard', ['plan' => 'couture']).'#catalog-panel"', 'Приміряти іншу річ',
            'class="set-ask only-desktop"', 'Потрібна думка стиліста?', 'Запитати стилістку',
        ], escape: false)
        ->assertSee('Видалити цю добірку')
        // The saved note and the model line are gone; the frame has neither.
        ->assertDontSee('збережено після примірки')
        ->assertDontSee('Модель: 00000040713');
});

it('opens a set on a phone as the try-on result: before/after, tiles, piece cards, the stylist', function () {
    config([
        'filesystems.disks.r2.key' => 'test-key',
        'filesystems.disks.r2.secret' => 'test-secret',
        'filesystems.disks.r2.bucket' => 'test-bucket',
        'filesystems.disks.r2.endpoint' => 'https://r2.test',
        'membership.stylist_booking_url' => 'https://book.example/stylist',
    ]);
    Storage::forgetDisk('r2');

    [$user, $product, $generation] = memberWithLook(['brand_name' => 'OneByOne', 'product_name' => 'TRF wide leg jeans', 'price_aed' => '599.00']);

    test()->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertSee('ba-before only-mobile">До<', escape: false)
        ->assertSee('ba-after only-mobile">Після<', escape: false)
        ->assertSee('id="m-set-'.$generation->id.'"', escape: false)
        ->assertSeeInOrder(['Зберегти', 'Поділитися', 'Завантажити'])
        ->assertSee(route('membership.account.looks.download', ['generation' => $generation->id]), escape: false)
        ->assertSee('id="m-piece-'.$generation->id.'-'.$product->id.'"', escape: false)
        ->assertSeeInOrder(['OneByOne', 'TRF wide leg jeans', '599.00 AED', 'Приміряти іншу', 'Купити'])
        ->assertSee(route('membership.account.dashboard', ['plan' => 'couture']).'#catalog-panel', escape: false)
        ->assertSee('Потрібна думка стиліста?')
        ->assertSee('href="https://book.example/stylist"', escape: false)
        // One look: nothing to compare it with.
        ->assertDontSee('startPicking('.$generation->id.')"', escape: false)
        // The old phone extras are gone.
        ->assertDontSee('Генерації AI')
        ->assertDontSee('id="wardrobe-view"', escape: false);
});

it('offers comparing from an opened set once there are two looks', function () {
    [$user, , $generation] = memberWithLook();
    Generation::factory()->done()->create(['lead_id' => $generation->lead_id, 'product_id' => $generation->product_id]);

    test()->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertSee('close(); startPicking('.$generation->id.')', escape: false);
});

it('keeps the stylist button inert without a booking link', function () {
    config(['membership.stylist_booking_url' => null]);
    [$user] = memberWithLook();

    test()->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertSee('<button type="button" class="m-btn m-btn-dark" disabled>Запитати стилістку</button>', escape: false);
});

it('lists every piece of an outfit chain, in order', function () {
    $user = User::factory()->emailOnly()->create();
    $jacket = Product::factory()->verified()->create(['product_name' => 'Жакет двобортний']);
    $trousers = Product::factory()->verified()->create(['product_name' => 'Штани широкого крою']);
    Generation::factory()->done()->outfit([$jacket->id, $trousers->id])->create([
        'lead_id' => Lead::factory()->forUser($user)->create()->id,
    ]);

    test()->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertSeeInOrder(['Жакет двобортний', 'Штани широкого крою']);
});

it('shows only the member\'s own finished try-ons', function () {
    [$user] = memberWithLook(['product_name' => 'My Own Dress']);

    // Someone else's look, and the member's own unfinished one, stay out.
    memberWithLook(['product_name' => 'Somebody Elses Coat']);
    Generation::factory()->failed()->create([
        'lead_id' => Lead::factory()->forUser($user)->create()->id,
        'product_id' => Product::factory()->verified()->create(['product_name' => 'Failed Attempt'])->id,
    ]);

    test()->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertSee('My Own Dress')
        ->assertDontSee('Somebody Elses Coat')
        ->assertDontSee('Failed Attempt');
});

it('shows the before/after slider from freshly signed urls when the disk can sign', function () {
    config([
        'filesystems.disks.r2.key' => 'test-key',
        'filesystems.disks.r2.secret' => 'test-secret',
        'filesystems.disks.r2.bucket' => 'test-bucket',
        'filesystems.disks.r2.endpoint' => 'https://r2.test',
    ]);
    Storage::forgetDisk('r2');

    [$user, $product, $generation] = memberWithLook();

    // Signing is local (no request leaves the box); the stored result url is
    // the fallback while the row carries no result_keys.
    test()->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertSee('class="compare"', escape: false)
        ->assertSee('alt="До"', escape: false)
        ->assertSee('X-Amz-Signature', escape: false)
        ->assertSee($generation->result_urls[0], escape: false);
});

it('falls back to a placeholder, never a broken image, when nothing can be signed', function () {
    Storage::fake('r2');

    [$user, $product, $generation] = memberWithLook();
    $generation->update(['result_urls' => null]);

    // A local disk cannot sign, so no "before" image: the raw R2 key must not
    // be pasted into the page as a substitute.
    test()->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertDontSee($generation->client_photo)
        ->assertDontSee('class="compare"', escape: false)
        ->assertSee('Зображення недоступне');
});

it('hardcodes no shop in the template', function () {
    $view = file_get_contents(resource_path('views/membership/wardrobe.blade.php'));

    expect($view)->not->toContain('namshi.com')
        ->and($view)->not->toContain('ounass.com');
});

it('keeps the frame\'s retention line free of deletion promises', function () {
    [$user] = memberWithLook();

    $page = test()->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertSee('*Ваші фото захищені та обробляються конфіденційно.');

    foreach (['після обробки', 'одразу', 'миттєво', 'immediately', 'instantly'] as $forbidden) {
        $page->assertDontSee($forbidden);
    }
});

it('stays locked below Couture, without the tabs', function () {
    [$user] = memberWithLook(['product_name' => 'Hidden Behind Lock']);

    test()->actingAs($user)->get('/account/wardrobe?plan=shopaholic')
        ->assertSee('id="wardrobe-locked"', escape: false)
        ->assertDontSee('id="wardrobe-tabs"', escape: false)
        ->assertDontSee('Hidden Behind Lock');
});

/*
 * --- desktop cards and comparing looks (Figma 1907:7761, 1917:8226) ---
 */

/** A member with two finished looks, each of its own piece. */
function memberWithTwoLooks(): array
{
    [$user, $first, $one] = memberWithLook(['brand_name' => 'OneByOne', 'product_name' => 'Сукня максі']);
    $second = Product::factory()->verified()->create(['brand_name' => 'Zara', 'product_name' => 'Жакет']);
    $two = Generation::factory()->done()->create([
        'lead_id' => Lead::factory()->forUser($user)->create()->id,
        'product_id' => $second->id,
    ]);

    return [$user, $first, $one, $second, $two];
}

it('puts a heart for the look\'s piece and a menu on every card, phone and desktop alike', function () {
    [$user, $product, $generation] = memberWithLook();

    test()->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertSeeInOrder([
            'id="look-card-'.$generation->id.'"',
            'class="card-tools" x-show="! picking"',
            // The heart keeps the look's piece in "Обране".
            'x-on:click="toggleFavorite('.$product->id.')"',
            'aria-label="Дії з образом"',
            'Переглянути',
            'href="'.route('membership.account.looks.download', ['generation' => $generation->id]).'" download', 'Завантажити',
            'x-on:click="menu = false; removeLook('.$generation->id.')"', 'Видалити',
        ], escape: false)
        // One look: nothing to compare it with.
        ->assertDontSee('id="compare-looks"', escape: false)
        ->assertDontSee('startPicking('.$generation->id.')', escape: false);
});

it('picks two or three looks to compare and shows them side by side', function () {
    [$user, $first, $one, $second, $two] = memberWithTwoLooks();

    $page = test()->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertSee('id="compare-looks"', escape: false)
        ->assertSee('Порівняти образи')
        ->assertSeeInOrder([
            'id="compare-banner" x-show="picking && ! comparing"',
            'Оберіть 2–3 образи для порівняння',
            'x-text="picked.length"', 'x-text="MAX_PICK"',
            'Скасувати',
            'id="compare-go" :disabled="picked.length < 2"', 'Порівняти',
            'id="compare-view" x-show="comparing"',
        ], escape: false)
        ->assertSee('x-on:click="menu = false; startPicking('.$one->id.')"', escape: false)
        ->assertSee('x-on:click="cardClick('.$two->id.')"', escape: false);

    // Each look carries its pieces for the side-by-side view, shop link from the product row.
    expect($page->viewData('compareLooks')->pluck('id')->all())->toEqualCanonicalizing([$one->id, $two->id])
        ->and($page->getContent())->toContain('MAX_PICK: 3,')
        ->and($page->getContent())->toContain(str_replace('/', '\/', route('membership.account.shop', ['product' => $second->id, 'from' => 'wardrobe'])));
});

it('carries no look in the page data while the wardrobe is locked', function () {
    [$user] = memberWithTwoLooks();

    test()->actingAs($user)->get('/account/wardrobe?plan=shopaholic')
        ->assertSee('id="wardrobe-locked"', escape: false)
        ->assertViewHas('compareLooks', []);
});

it('draws the card icons and the head as Figma 1907:7761 does', function () {
    $view = file_get_contents(resource_path('views/membership/wardrobe.blade.php'));

    // The frame's own icons (1917:7887 / 1917:7889): #2D2D2D glyphs drawn across
    // a 32-unit box that fills the white round — the old 20px ones read pale.
    expect($view)->toContain('.card-tool svg { display: block; width: 100%; height: 100%; }')
        // Heart and menu on a look card and on a favourite card.
        ->and(substr_count($view, '<svg width="32" height="32" viewBox="0 0 32 32" fill="none" aria-hidden="true"><path'))->toBe(4)
        ->and($view)->toContain('M16.1306 13.7832C15.5079')   // the menu's dots
        ->and($view)->toContain('M16 26C15.8619 25.9996')      // the heart
        ->and($view)->not->toContain('<circle cx="4.5" cy="10" r="1.6" fill="currentColor"/>');

    // The tabs stand level with the heading's foot; 15px lede, then the bar 15 / 24.
    expect($view)->toContain('#wardrobe-section > .dl-head .wardrobe-tabs { grid-column: 2; grid-row: 1 / span 2; }')
        ->and($view)->toContain('#wardrobe-section > .dl-head .lede { grid-column: 1 / -1; grid-row: 3; margin-top: 15px; }')
        ->and($view)->toContain('margin: 15px 0 24px; }')
        ->and($view)->toContain('.wardrobe-tabs .dl-btn { min-height: 58px; }');
});

it('puts the look\'s piece and its buttons under every wardrobe card (Figma 1907:7903)', function () {
    // Bug: "Мій гардероб" showed only the look's picture; the frame draws the
    // piece's brand, name and price under it, then "Приміряти" and "Купити".
    [$user, $piece, $generation] = memberWithLook(['brand_name' => 'ONEBYONE', 'product_name' => 'Сукня з драпіруванням']);

    test()->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertSeeInOrder([
            'id="look-card-'.$generation->id.'"',
            'aria-label="Дії з образом"',
            'class="brand">'.$piece->brand_name,
            'class="name" title="'.$piece->product_name.'"',
            'AED',
            '<div class="actions" x-show="! picking">',
            'Приміряти',
            'href="'.route('membership.account.shop', ['product' => $piece->id, 'from' => 'wardrobe']).'" target="_blank" rel="noopener">Купити',
        ], escape: false);

    expect(file_get_contents(resource_path('views/membership/wardrobe.blade.php')))
        ->toContain('.look-cards .look-card { gap: 20px; border: none; overflow: visible; }');
});

it('lays "Обране" out as the cards of Figma 1907:7903', function () {
    // Bug: favourites were list rows (picture left, text right, one shop
    // button) where the frame draws a grid of cards like the looks'.
    [$user] = memberWithLook();
    $piece = Product::factory()->verified()->ofCategory('dresses')->create(['brand_name' => 'ONEBYONE', 'product_name' => 'Сукня з драпіруванням']);
    $user->favorites()->create(['product_id' => $piece->id]);

    test()->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertSeeInOrder([
            'class="fav-cards" id="favorites-list"',
            'class="fav-card" id="fav-'.$piece->id.'"',
            'class="card-tool active" aria-pressed="true" aria-label="Прибрати з обраного"',
            'aria-label="Дії з річчю"',
            'Прибрати з обраного',
            'class="brand">ONEBYONE', 'Сукня з драпіруванням',
            'href="'.route('membership.account.dashboard', ['pick' => $piece->id, 'plan' => 'couture']).'#catalog-grid">Приміряти',
            'href="'.route('membership.account.shop', ['product' => $piece->id, 'from' => 'wardrobe']).'" target="_blank" rel="noopener">Купити',
        ], escape: false);

    $view = file_get_contents(resource_path('views/membership/wardrobe.blade.php'));
    expect($view)->toContain('.fav-card .pic { position: relative; aspect-ratio: 380 / 413; border-radius: 14px;')
        ->and($view)->toContain(':is(.fav-card, .look-card) .actions { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: auto; }')
        ->and($view)->not->toContain('class="pieces fav-list"');
});

it('asks before removing a look (Figma 1878:7507)', function () {
    [$user] = memberWithLook();

    $page = test()->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertSeeInOrder([
            '<template x-teleport="body">',
            'id="delete-look-dialog" x-show="toDelete !== null"',
            'role="alertdialog"',
            'Видалити образ',
            'Образ буде прибрано з вашого гардеробу. Цю дію не можна скасувати.',
            'x-on:click="toDelete = null">Скасувати',
            'id="delete-look-confirm" x-on:click="confirmRemove()">Видалити',
        ], escape: false);

    // The bin reads at the frame's size: a 42px ring on the phone with the bin
    // about half of it (was an 18px svg, a ~9px bin), larger on a desktop.
    $view = file_get_contents(resource_path('views/membership/wardrobe.blade.php'));
    expect($view)->toContain('<span class="confirm-icon" aria-hidden="true">'."\n".'                        <svg width="46" height="46" viewBox="0 0 28 28"')
        ->and($view)->toContain(".confirm-icon { width: 42px; height: 42px; }\n            .confirm-icon svg { width: 32px; height: 32px; }");

    // The menu and the set only ask; the dialog's button removes.
    expect($page->getContent())
        ->toMatch('/removeLook\(id\) \{\s+this\.toDelete = id;\s+\}/')
        ->toMatch('/async confirmRemove\(\) \{\s+const id = this\.toDelete;/');
});

it('lays the wardrobe out on a phone as Figma 1874:8247 draws it', function () {
    [$user] = memberWithTwoLooks();
    $view = file_get_contents(resource_path('views/membership/wardrobe.blade.php'));

    test()->actingAs($user)->get('/account/wardrobe?plan=couture')
        // The card's own "Переглянути" button and bin are gone: the picture opens it, the menu removes it.
        ->assertDontSee('class="trash', escape: false)
        ->assertDontSee('dl-btn-block only-mobile" x-on:click="show(', escape: false)
        // The chips scroll sideways over a line that shows where.
        ->assertSee('class="chip-meter only-mobile"', escape: false)
        // Compare is on the phone too, full width.
        ->assertSee('class="dl-btn dl-btn-outline compare-looks" id="compare-looks"', escape: false);

    // The phone's looks are 3:4 — a 3:4 box shows them whole with no bars, inside a padded card.
    expect($view)->toContain('.look-card .pic { aspect-ratio: 3 / 4; border-radius: 6px; background: transparent; }')
        ->and($view)->toContain('.fav-card .pic { aspect-ratio: 3 / 4; border-radius: 6px; background: transparent; }')
        ->and($view)->toContain('.look-cards .look-card { padding: 6px; border-radius: 8px; }')
        ->and($view)->toContain('.card-tool { width: 18px; height: 18px; }')
        ->and($view)->toContain('.cat-chips { flex-wrap: nowrap; gap: 6px; margin: 12px 0 0; overflow-x: auto; scrollbar-width: none; }')
        ->and($view)->not->toContain('.wardrobe-tabs .dl-btn svg { order: 2; }');
});
