<?php

/*
 * The product detail modal on the account dashboard (opened by a catalog
 * card's picture, Figma Desk_lk 1661:8117): the feed's description, every photo
 * of the piece, and "Стилізуй з цим" — pieces from other chips to pair it
 * with. Sizes and colours from the frame are not drawn: no feed carries them.
 */

use App\Models\Product;
use App\Models\User;
use App\Services\Catalog\StylePairings;

beforeEach(function () {
    config(['features.membership' => true]);
});

/*
 * --- description ---
 */

it('reads a Google Merchant description', function () {
    expect(Product::factory()->described('Silk, bias cut.')->make()->description())->toBe('Silk, bias cut.');
});

it('reads the Amazon editorial review, single or listed', function () {
    $single = Product::factory()->make(['raw' => ['EditorialReviews' => ['EditorialReview' => [
        'Source' => 'Demo', 'Content' => '  Комплект з курткою.  ',
    ]]]]);
    $listed = Product::factory()->make(['raw' => ['EditorialReviews' => ['EditorialReview' => [
        ['Source' => 'Demo', 'Content' => ''],
        ['Source' => 'Demo', 'Content' => 'Second review'],
    ]]]]);

    expect($single->description())->toBe('Комплект з курткою.')
        ->and($listed->description())->toBe('Second review');
});

it('has no description when the feed gave none', function () {
    expect(Product::factory()->make()->description())->toBeNull()
        ->and(Product::factory()->make(['raw' => ['description' => '   ']])->description())->toBeNull();
});

/*
 * --- gallery ---
 */

it('puts the try-on photo first, then the feed\'s extra photos, each once', function () {
    $product = Product::factory()->withGallery([
        'https://cdn.example.test/b.jpg',
        'https://cdn.example.test/c.jpg',
        'https://cdn.example.test/b.jpg',
    ])->make(['image_flat_url' => 'https://cdn.example.test/a.jpg']);

    expect($product->galleryUrls())->toBe([
        'https://cdn.example.test/a.jpg',
        'https://cdn.example.test/b.jpg',
        'https://cdn.example.test/c.jpg',
    ]);
});

it('reads Amazon ImageSets and skips relative or empty paths', function () {
    $product = Product::factory()->make([
        'image_flat_url' => 'https://cdn.example.test/a.jpg',
        'raw' => ['ImageSets' => ['ImageSet' => [
            ['LargeImage' => ['URL' => 'https://cdn.example.test/side.jpg']],
            ['LargeImage' => ['URL' => 'images/B0XXXX.jpg']],
            ['LargeImage' => []],
        ]]],
    ]);

    expect($product->galleryUrls())->toBe(['https://cdn.example.test/a.jpg', 'https://cdn.example.test/side.jpg']);
});

it('is a one-photo gallery for a feed with a single image', function () {
    $product = Product::factory()->make(['image_flat_url' => 'https://cdn.example.test/a.jpg']);

    expect($product->galleryUrls())->toBe(['https://cdn.example.test/a.jpg']);
});

/*
 * --- "Стилізуй з цим" ---
 */

it('pairs a bottom with tops first, never itself or its own chip', function () {
    $trousers = Product::factory()->verified()->ofCategory('lower_body')->ofFeedCategory('Брюки и джинсы')->create();
    $jeans = Product::factory()->verified()->ofCategory('lower_body')->ofFeedCategory('Брюки и джинсы')->create();
    $skirt = Product::factory()->verified()->ofCategory('lower_body')->ofFeedCategory('Юбки')->create();
    $top = Product::factory()->verified()->ofCategory('upper_body')->ofFeedCategory('Топы и блузы')->create();
    $dress = Product::factory()->verified()->ofCategory('dresses')->ofFeedCategory('Платья')->create();

    $pairs = StylePairings::for(Product::all())[$trousers->id];

    expect($pairs[0])->toBe($top->id)
        ->and($pairs)->toContain($skirt->id, $dress->id)
        ->and($pairs)->not->toContain($trousers->id, $jeans->id);
});

it('puts a top or jacket over a dress, and stops at the limit', function () {
    $dress = Product::factory()->verified()->ofCategory('dresses')->ofFeedCategory('Платья')->create();
    $tops = Product::factory()->count(5)->verified()->ofCategory('upper_body')->ofFeedCategory('Топы и блузы')->create();
    Product::factory()->verified()->ofCategory('lower_body')->ofFeedCategory('Юбки')->create();

    $pairs = StylePairings::for(Product::all())[$dress->id];

    expect($pairs)->toHaveCount(4)
        ->and(array_diff($pairs, $tops->pluck('id')->all()))->toBe([]);
});

it('pairs nothing when every piece shares one chip', function () {
    $a = Product::factory()->verified()->ofFeedCategory('Платья')->create();
    Product::factory()->verified()->ofFeedCategory('Платья')->create();

    expect(StylePairings::for(Product::all())[$a->id])->toBe([]);
});

/*
 * --- on the page ---
 */

it('opens the detail from the card\'s picture, with no separate info icon, and puts the modal on the page', function () {
    $product = Product::factory()->verified()->create(['product_name' => 'Linen shirt']);

    test()->actingAs(User::factory()->emailOnly()->onboarded()->create())->get('/account')
        ->assertOk()
        ->assertSee('<button type="button" class="pic" aria-label="Детальніше: Linen shirt"', escape: false)
        ->assertSee('x-on:click.stop="openDetail('.$product->id.')"', escape: false)
        ->assertDontSee('class="more"', escape: false)
        ->assertSee('id="product-detail"', escape: false)
        ->assertSee('Обрати для примірки')
        ->assertSee('Додати до обраного')
        ->assertSee('Стилізуй з цим')
        ->assertSee('Готові поєднання з гардеробом')
        // Sizes and colours are not drawn: no feed carries them.
        ->assertDontSee('>Розміри<', escape: false)
        ->assertDontSee('>Кольори<', escape: false);
});

it('draws the phone\'s own product card next to the desktop modal (Figma 2093:9429)', function () {
    Product::factory()->verified()->create();

    test()->actingAs(User::factory()->emailOnly()->onboarded()->create())->get('/account')
        ->assertOk()
        // Each surface shows only its own version.
        ->assertSee('class="pd dl only-desktop" id="product-detail"', escape: false)
        ->assertSee('class="pm dl only-mobile" id="m-product-detail"', escape: false)
        // Back and like on the picture, the photo count and a bar per photo.
        ->assertSee('class="pm-round pm-back" x-ref="detailBack" aria-label="Назад"', escape: false)
        ->assertSee('id="m-pd-like"', escape: false)
        ->assertSee("x-text=\"':n з :total'.replace(':n', detailImage + 1).replace(':total', detail.images.length)\"", escape: false)
        ->assertSee('x-on:click="showDetailImage(i)"', escape: false)
        // Brand | ID, name, price, then the button that tries the piece on.
        ->assertSee("x-text=\"'ID ' + detail.externalId\"", escape: false)
        ->assertSee('id="pm-name"', escape: false)
        ->assertSee('id="m-pd-go" x-on:click="generateFromDetail()"', escape: false)
        ->assertSee("canGenerate(detail.id) ? 'Згенерувати образ' : 'Обрати для примірки'", escape: false);
});

it('keeps the phone card\'s photo count lowercase, so "1 з 4" never reads as "1 3 4"', function () {
    $css = file_get_contents(resource_path('views/membership/dashboard.blade.php'));

    expect(preg_match('/\.pm-count \{[^}]*\}/', $css, $rule))->toBe(1)
        ->and($rule[0])->not->toContain('uppercase');
});

it('hands the modal each piece\'s id, description, photos and pairings', function () {
    $skirt = Product::factory()->verified()->ofCategory('lower_body')->ofFeedCategory('Юбки')
        ->described('A-line midi.')->withGallery(['https://cdn.example.test/back.jpg'])
        ->create(['external_id' => 'SKIRT-1', 'image_flat_url' => 'https://cdn.example.test/front.jpg']);
    $top = Product::factory()->verified()->ofCategory('upper_body')->ofFeedCategory('Топы и блузы')->create();

    $details = test()->actingAs(User::factory()->emailOnly()->onboarded()->create())
        ->get('/account')->viewData('productDetails');

    expect($details[$skirt->id])->toMatchArray([
        'externalId' => 'SKIRT-1',
        'description' => 'A-line midi.',
        'images' => ['https://cdn.example.test/front.jpg', 'https://cdn.example.test/back.jpg'],
        'pairs' => [$top->id],
    ]);
});
