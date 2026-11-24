<?php

/*
 * The two-step "Примірка", next to the one-screen version on the phone's
 * dashboard and in the same site palette:
 * /account/try-on/step-1 (Крок 1 · Додай фото) takes the photo into the session,
 * /account/try-on/step-2 (Крок 2 · Обери одяг) picks pieces from the verified
 * catalog and runs the try-on on the dashboard's endpoints with the member's
 * own lead. Copy comes from lang/{uk,en}/tryon_steps.php.
 */

use App\Http\Controllers\Membership\TryOnController;
use App\Models\ColorProfile;
use App\Models\PhotoConsent;
use App\Models\Product;
use App\Models\ProductSeasonMatch;
use App\Models\User;
use App\Services\Catalog\TryOnChips;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['features.membership' => true]);
    Storage::fake('r2');
});

function stepsMember(): User
{
    return User::factory()->emailOnly()->onboarded()->create();
}

/** A photo already uploaded on step 1: in storage and in the session. */
function withStepsPhoto($test, string $key = 'uploads/2026-10-19/me.jpg'): mixed
{
    Storage::disk('r2')->put($key, 'jpeg-bytes');

    return $test->withSession([TryOnController::SESSION_KEY => ['key' => $key, 'consent_id' => 7]]);
}

function stepsPhotoPayload(array $overrides = []): array
{
    return array_merge([
        'photo' => UploadedFile::fake()->image('me.jpg', 600, 900),
        'consent_accepted' => '1',
        'consent_version' => config('consent.version'),
        'terms_accepted' => '1',
        'terms_version' => config('terms.version'),
    ], $overrides);
}

// ---- access ----

it('is for signed-in members only', function (string $url) {
    $this->get($url)->assertRedirect(route('login'));
})->with(['/account/try-on/step-1', '/account/try-on/step-2', '/account/try-on/photo']);

it('is closed with the membership flag', function (string $url) {
    config(['features.membership' => false]);

    $this->actingAs(stepsMember())->get($url)->assertNotFound();
})->with(['/account/try-on/step-1', '/account/try-on/step-2']);

// ---- step 1 ----

it('lays step 1 out top to bottom as step1-upload.html', function () {
    $this->actingAs(stepsMember())
        ->get('/account/try-on/step-1')
        ->assertOk()
        ->assertSeeInOrder([
            'Atelier Fit', 'by Atelier Fit',
            'AI<br>Try-On', 'Побач, як виглядає одяг на тобі до покупки.',
            'Повний образ', 'Якісне освітлення', 'Твоя фігура видима',
            '<span class="ty-num">1</span>', 'Крок 1 · Додай фото',
            'Додати фото', 'Завантаж повноцінне фото у повний зріст, щоб почати примірку.',
            'Додати фото', 'JPG, PNG, WebP до 12 МБ',
            'Головна', 'Примірка', 'Гардероб', 'Улюблене', 'Профіль',
        ], escape: false);
});

it('paints both steps in the site palette, with the screen\'s faces', function () {
    $html = $this->actingAs(stepsMember())->get('/account/try-on/step-1')->getContent();

    foreach ([
        '--ink: #2D2D2D', '--surface: #FFFFFF', '--surface-2: #F0F0F0', '--muted: #6B6B6B',
        '--border: #E6E6E6', '--dashed: #BDBDBD',
        "'Playfair Display'", "'Manrope'",
    ] as $token) {
        expect($html)->toContain($token);
    }

    // No cream, brown or gold left; step numbers are dark circles with a white digit.
    foreach (['layout', 'upload', 'select'] as $view) {
        $source = file_get_contents(resource_path("views/membership/tryon/{$view}.blade.php"));
        foreach (['#F4EFE7', '#FBF8F3', '#ECE4D8', '#2B231D', '#5B3F2E', '#C8A97C', '#3E7A4F', '--gold', '--accent'] as $warm) {
            expect($source)->not->toContain($warm);
        }
    }
    expect($html)->toContain('.ty-num { width: 26px; height: 26px; border-radius: 13px; background: var(--ink); color: var(--surface);');
});

// Step 1 fits the viewport's height the way the phone's dashboard does
// (html.m-fit): no page scroll, the upload card takes what is left, the hero
// gives when there is not enough, short phones drop the tips and the icon.
it('fits step 1 into one viewport, the upload card giving as the dashboard\'s card row', function () {
    $html = $this->actingAs(stepsMember())->get('/account/try-on/step-1')->getContent();

    expect($html)
        ->toContain('<html lang="uk" dir="ltr" class="ty-fit">')
        ->toContain('html.ty-fit, html.ty-fit body { height: 100%; overflow: hidden; }')
        ->toContain('html.ty-fit .screen { height: 100vh; height: 100dvh; min-height: 0; }')
        ->toContain('html.ty-fit .screen > main { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }')
        ->toContain('<main class="t1-main"')
        ->toContain('.t1-main > * { flex-shrink: 0; }')
        ->toContain('.t1-card-wrap { padding: 14px 20px 0 20px; flex: 1 0 auto; display: flex; flex-direction: column; }')
        ->toContain('.t1-card { flex: 1 0 auto;')
        ->toContain('.t1-upload { margin-top: auto;')
        ->toContain('flex: 0 1 auto; min-height: 168px; overflow: hidden; }')
        ->toContain('.t1-hero-pic { width: 148px; height: 236px; max-height: 100%;')
        ->toContain('@media (max-height: 700px)')
        ->toContain('.t1-tips { display: none; }')
        ->toContain('.t1-icon { display: none; }');
});

// Step 2 the same way: the grid is the block that gives and scrolls inside,
// under the sticky button; the "nothing found" line lives in the grid so it
// sits under the chips, not at the bottom of the screen.
it('fits step 2 into one viewport, the grid scrolling inside under the button', function () {
    $html = withStepsPhoto($this->actingAs(stepsMember()))->get('/account/try-on/step-2')->getContent();

    expect($html)
        ->toContain('<html lang="uk" dir="ltr" class="ty-fit">')
        ->toContain('html.ty-fit .screen > main { flex: 1 1 auto; min-height: 0; display: flex; flex-direction: column; }')
        ->toContain('.t2 > * { flex-shrink: 0; }')
        ->toContain('.t2-grid { flex: 1 1 auto; min-height: 0; overflow-y: auto; overscroll-behavior: contain; scrollbar-width: none;')
        ->toContain('padding: 10px 20px 104px 20px; align-content: start; }')
        ->toContain('.t2-empty { grid-column: 1 / -1;')
        ->not->toContain('.t2 { padding-bottom: 104px; }')
        ->toContain('@media (max-height: 700px)')
        ->toContain('.t2-card-pic { height: 150px; }');

    // The empty line is the grid's last child.
    expect(preg_match('/<p class="t2-empty" id="tryon-empty"[^>]*>[^<]*<\/p>\s*<\/div>/', $html))->toBe(1);
});

it('shows the transparent model picture with no backing', function () {
    expect(public_path('images/tryon/hero-model.png'))->toBeFile();

    $this->actingAs(stepsMember())
        ->get('/account/try-on/step-1')
        ->assertSee('id="tryon-hero" src="'.asset('images/tryon/hero-model.png').'"', escape: false)
        ->assertSee('overflow: hidden; flex-shrink: 0; background: transparent; }', escape: false);
});

it('accepts only JPEG, PNG and WebP photos up to 12 MB', function () {
    $this->actingAs(stepsMember())
        ->get('/account/try-on/step-1')
        ->assertSee('type="file" accept="image/jpeg,image/png,image/webp"', escape: false)
        ->assertSee('file.size > 12 * 1024 * 1024', escape: false)
        ->assertSee('this.error = this.t.too_big', escape: false)
        ->assertSee('window.location.href = data.redirect', escape: false);
});

it('stores the photo, keeps it in the session and sends the member on to step 2', function () {
    $this->actingAs(stepsMember())
        ->post('/account/try-on/photo', stepsPhotoPayload(), ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJson(['redirect' => route('membership.account.try-on.step2')]);

    $photo = session(TryOnController::SESSION_KEY);

    expect($photo['key'])->toStartWith('uploads/')
        ->and($photo['consent_id'])->toBe(PhotoConsent::sole()->id);
    Storage::disk('r2')->assertExists($photo['key']);
});

it('keeps ?lang=en for step 2 without carrying it in the link', function () {
    // SetLocale remembers the choice, so the next step needs no ?lang of its own.
    $this->actingAs(stepsMember())
        ->post('/account/try-on/photo?lang=en', stepsPhotoPayload(), ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJson(['redirect' => route('membership.account.try-on.step2')])
        ->assertSessionHas('locale', 'en');

    $this->get('/account/try-on/step-2')
        ->assertOk()
        ->assertSee('<html lang="en" dir="ltr" class="ty-fit">', escape: false);
});

it('refuses a file that is too large or not a photo, and remembers nothing', function (UploadedFile $file) {
    $this->actingAs(stepsMember())
        ->post('/account/try-on/photo', stepsPhotoPayload(['photo' => $file]), ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertSessionMissing(TryOnController::SESSION_KEY);

    expect(Storage::disk('r2')->allFiles())->toBe([]);
})->with([
    'over 12 MB' => fn () => UploadedFile::fake()->image('big.jpg')->size(12289),
    'a text file' => fn () => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
]);

it('refuses a photo without the consent', function () {
    $this->actingAs(stepsMember())
        ->post('/account/try-on/photo', stepsPhotoPayload(['consent_accepted' => '0']), ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertSessionMissing(TryOnController::SESSION_KEY);
});

// ---- step 2 ----

it('sends step 2 back to step 1 without a photo in the session', function () {
    $this->actingAs(stepsMember())->get('/account/try-on/step-2')->assertRedirect(route('membership.account.try-on.step1'));
    $this->actingAs(stepsMember())->get('/account/try-on/step-2?lang=en')->assertRedirect(route('membership.account.try-on.step1'));
});

it('sends step 2 back to step 1 once the photo has left storage', function () {
    $this->actingAs(stepsMember())
        ->withSession([TryOnController::SESSION_KEY => ['key' => 'uploads/2026-10-15/gone.jpg', 'consent_id' => null]])
        ->get('/account/try-on/step-2')
        ->assertRedirect(route('membership.account.try-on.step1'))
        ->assertSessionMissing(TryOnController::SESSION_KEY);
});

it('lays step 2 out top to bottom as step2-select.html', function () {
    config(['fitting_room.outfit.max_items' => 3]);
    Product::factory()->verified()->dresses()->create();

    withStepsPhoto($this->actingAs(stepsMember()))
        ->get('/account/try-on/step-2')
        ->assertOk()
        ->assertSeeInOrder([
            'Atelier Fit', 'by Atelier Fit',
            'id="tryon-photo" src="'.route('membership.account.try-on.photo').'"', 'Фото додано', 'Змінити',
            '<span class="ty-num">2</span>', 'Обери одяг',
            'placeholder="Бренд або товар"', 'Усі бренди',
            'Для тебе', 'Усі', 'Сукні', 'Топи', 'Низ', 'Піджаки', 'Взуття',
            'Можна обрати до 3 речей', 'Обрано: 0',
            'id="tryon-grid"', 'Приміряти',
            'Згенерувати образ', '0 речей',
            'Головна', 'Примірка', 'Гардероб', 'Улюблене', 'Профіль',
        ], escape: false);
});

it('draws exactly the spec chips, never the feed categories', function () {
    Product::factory()->verified()->dresses()->ofFeedCategory('Спорт')->create();

    $html = withStepsPhoto($this->actingAs(stepsMember()))->get('/account/try-on/step-2')->getContent();

    preg_match_all('/class="t2-chip"[^>]*>([^<]+)</', $html, $chips);
    expect($chips[1])->toBe(['Для тебе', 'Усі', 'Сукні', 'Топи', 'Низ', 'Піджаки', 'Взуття']);
});

it('files every piece under one spec chip', function () {
    expect(TryOnChips::of(Product::factory()->dresses()->make(['feed_category' => 'Платья'])))->toBe('dresses')
        ->and(TryOnChips::of(Product::factory()->upperBody()->make(['feed_category' => 'Топы и блузы'])))->toBe('tops')
        ->and(TryOnChips::of(Product::factory()->lowerBody()->make(['feed_category' => null])))->toBe('bottoms')
        ->and(TryOnChips::of(Product::factory()->upperBody()->make(['feed_category' => 'Костюмы и жакеты'])))->toBe('jackets')
        ->and(TryOnChips::of(Product::factory()->upperBody()->make(['feed_category' => 'BLAZERS'])))->toBe('jackets')
        ->and(TryOnChips::of(Product::factory()->shoes()->make()))->toBe('shoes');
});

it('shows the verified catalog as cards, names as the catalog holds them', function () {
    $dress = Product::factory()->verified()->dresses()->create([
        'brand_name' => 'ZARA', 'product_name' => 'Linen midi dress', 'price_aed' => 199,
        'image_flat_url' => 'https://cdn.example.test/dress.jpg',
    ]);
    Product::factory()->unverified()->create(['product_name' => 'Не перевірена річ']);

    withStepsPhoto($this->actingAs(stepsMember()))
        ->get('/account/try-on/step-2')
        ->assertSeeInOrder(['ZARA', 'Linen midi dress', 'AED 199', 'Приміряти'])
        ->assertSee('https://cdn.example.test/dress.jpg', escape: false)
        ->assertSee("togglePick({$dress->id})", escape: false)
        ->assertSee('"chip":"dresses"', escape: false)
        ->assertDontSee('Не перевірена річ')
        ->assertDontSee('assets/product-', escape: false);
});

it('offers the brands of the catalog in the brand picker', function () {
    Product::factory()->verified()->create(['brand_name' => 'OYSHO']);
    Product::factory()->verified()->create(['brand_name' => 'MANGO']);

    withStepsPhoto($this->actingAs(stepsMember()))
        ->get('/account/try-on/step-2')
        ->assertSeeInOrder(['Усі бренди', 'MANGO', 'OYSHO']);
});

it('starts on "Для тебе", filled with the pieces that suit the member\'s season', function () {
    config(['features.color_analysis' => true]);
    $member = stepsMember();
    $profile = ColorProfile::factory()->for($member)->classified('deep_winter')->create();
    $member->forceFill(['color_profile_id' => $profile->id])->save();

    $suits = Product::factory()->verified()->create();
    ProductSeasonMatch::factory()->for($suits)->forSeason('deep_winter')->neutral()->scored(0.8)->create();
    Product::factory()->verified()->create();

    withStepsPhoto($this->actingAs($member))
        ->get('/account/try-on/step-2')
        ->assertSee("chip: 'for-you'", escape: false)
        ->assertSee('forYou: ['.$suits->id.']', escape: false);
});

it('toggles "Приміряти" up to the configured limit, with a toast past it', function () {
    config(['fitting_room.outfit.max_items' => 2]);
    Product::factory()->verified()->create();

    withStepsPhoto($this->actingAs(stepsMember()))
        ->get('/account/try-on/step-2')
        ->assertSee('Можна обрати до 2 речей')
        ->assertSee('maxItems: 2', escape: false)
        ->assertSee('if (this.picked.length >= this.maxItems) {', escape: false)
        ->assertSee('this.say(this.t.max_items.replace(\':count\', this.maxItems))', escape: false)
        ->assertSee('t.in_tryon', escape: false);
});

it('keeps the CTA off until a piece is picked, and counts the pieces in it', function () {
    withStepsPhoto($this->actingAs(stepsMember()))
        ->get('/account/try-on/step-2')
        ->assertSee(':disabled="! canGenerate()"', escape: false)
        ->assertSee('this.photoKey && this.picked.length', escape: false)
        ->assertSee('x-text="itemsLabel(picked.length)"', escape: false)
        ->assertSee('x-text="t.picked_count.replace(\':count\', picked.length)"', escape: false);

    $items = require lang_path('uk/tryon_steps.php');
    expect($items['items'])->toBe(['one' => ':count річ', 'few' => ':count речі', 'many' => ':count речей', 'other' => ':count речі']);
});

it('runs the try-on on the dashboard endpoints with the member\'s own lead and the session photo', function () {
    $html = withStepsPhoto($this->actingAs(stepsMember()), 'uploads/2026-10-19/mine.jpg')
        ->get('/account/try-on/step-2')
        ->getContent();

    expect($html)
        ->toContain(json_encode(route('fitting-room.generate')))
        ->toContain(json_encode(route('fitting-room.generate-outfit')))
        ->toContain('photoKey: "uploads\/2026-10-19\/mine.jpg"')
        ->toContain('consentId: 7')
        ->toMatch('/memberLead: \{"id":\d+,"token":"[^"]+"\}/');
});

it('serves the member their own session photo for the avatar, and nothing else', function () {
    $member = stepsMember();

    withStepsPhoto($this->actingAs($member), 'uploads/2026-10-19/mine.jpg')
        ->get('/account/try-on/photo?key=uploads/2026-10-19/other.jpg')
        ->assertOk()
        ->assertStreamedContent('jpeg-bytes');

    session()->forget(TryOnController::SESSION_KEY);
    $this->actingAs($member)->get('/account/try-on/photo')->assertNotFound();
});

it('marks "Примірка" as the current tab on both steps', function () {
    $this->actingAs(stepsMember())
        ->get('/account/try-on/step-1')
        ->assertSeeInOrder(['aria-current="page"', 'Примірка'], escape: false);

    withStepsPhoto($this->actingAs(stepsMember()))
        ->get('/account/try-on/step-2')
        ->assertSeeInOrder(['aria-current="page"', 'Примірка'], escape: false);
});

it('speaks English with ?lang=en', function () {
    $this->actingAs(stepsMember())
        ->get('/account/try-on/step-1?lang=en')
        ->assertOk()
        ->assertSee('<html lang="en" dir="ltr" class="ty-fit">', escape: false)
        ->assertSeeInOrder(['Try-On', 'Step 1 · Add a photo', 'Add photo', 'Home'])
        ->assertDontSee('Крок 1');

    withStepsPhoto($this->actingAs(stepsMember()))
        ->get('/account/try-on/step-2?lang=en')
        ->assertOk()
        ->assertSeeInOrder(['Photo added', 'Change', 'Pick clothes', 'For you', 'All', 'Dresses', 'Tops', 'Bottoms', 'Jackets', 'Shoes', 'Generate look'])
        ->assertSee(route('membership.account.try-on.step1'), escape: false)
        ->assertDontSee('Обери одяг');
});

it('has the same keys in both locales', function () {
    $keys = fn (array $lines) => array_keys(Arr::dot($lines));

    expect($keys(require lang_path('en/tryon_steps.php')))->toBe($keys(require lang_path('uk/tryon_steps.php')));
});
