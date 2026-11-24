<?php

use App\Models\ColorProfile;
use App\Models\Favorite;
use App\Models\Product;
use App\Models\ProductClick;
use App\Models\ProductSeasonMatch;
use App\Models\User;

/*
 * /account/capsules: one capsule per occasion; /account/capsules/{occasion}:
 * the pieces its looks use, each with its own shop button, then the looks.
 * Without a season there are no looks — the list says how to get one.
 */

beforeEach(fn () => config(['features.membership' => true, 'features.color_analysis' => true]));

function capsuleMember(?string $season = 'deep_winter'): User
{
    $member = User::factory()->emailOnly()->onboarded()->create();

    if ($season !== null) {
        $profile = ColorProfile::factory()->for($member)->classified($season)->create();
        $member->forceFill(['color_profile_id' => $profile->id])->save();
    }

    return $member;
}

function capsuleCatalogPiece(string $category, string $occasion = 'universal', array $attributes = []): Product
{
    $factory = Product::factory()->verified()->ofCategory($category)->measured();
    if ($occasion !== 'universal') {
        $factory = $factory->forOccasion($occasion);
    }

    $product = $factory->create($attributes);
    ProductSeasonMatch::factory()->for($product)->forSeason('deep_winter')->neutral()->scored(0.8)->create();

    return $product;
}

it('lists one capsule per occasion, each opening its own page', function () {
    capsuleCatalogPiece('upper_body', 'office');
    capsuleCatalogPiece('lower_body');
    capsuleCatalogPiece('shoes');

    $response = $this->actingAs(capsuleMember())->get('/account/capsules?plan=vip')->assertOk();

    $response->assertSee('id="capsule-cards"', escape: false)
        ->assertSee('id="capsule-office"', escape: false)
        ->assertSee('id="capsule-evening"', escape: false)
        ->assertSee('id="capsule-casual"', escape: false)
        ->assertSee('Офіс')
        ->assertSee('Вечір')
        // The office capsule: one look of three pieces, in their colours.
        ->assertSee('3 речі')
        ->assertSee('background: #1B2A4A;', escape: false)
        ->assertSee(route('membership.account.capsule', ['occasion' => 'office', 'plan' => 'vip']), escape: false)
        ->assertSee('Переглянути капсулу')
        // Evening needs a piece made for it; there is none, so no count.
        ->assertSee('Образи ще збираються');
});

it('covers every capsule card with the photo from the frame, not the piece strip', function () {
    capsuleCatalogPiece('upper_body', 'office');
    capsuleCatalogPiece('lower_body');
    capsuleCatalogPiece('shoes');

    expect(public_path('images/capsules/cover.jpg'))->toBeFile();

    $page = $this->actingAs(capsuleMember())->get('/account/capsules?plan=vip')->assertOk();

    // One shared photo per occasion card (office, evening, casual).
    expect(substr_count($page->getContent(), 'class="cover-photo" src="'.asset('images/capsules/cover.jpg').'"'))->toBe(3);
    $page->assertDontSee('<div class="cover-strip">', escape: false);

    // Sharp on every screen: the blur read as a broken image on a desktop.
    expect(file_get_contents(resource_path('views/membership/capsules.blade.php')))->not->toContain('blur(');
});

it('shows a capsule as its pieces, then the looks they make', function () {
    $top = capsuleCatalogPiece('upper_body', 'office', ['brand_name' => 'Mango', 'product_name' => 'Silk office blouse', 'price_aed' => '320.00']);
    capsuleCatalogPiece('lower_body');
    $shoes = capsuleCatalogPiece('shoes');

    $response = $this->actingAs(capsuleMember())->get('/account/capsules/office?plan=vip')->assertOk();

    $response->assertSee('id="capsule-pieces"', escape: false)
        ->assertSee('3 речі капсули')
        ->assertSee(route('membership.account.capsules', ['plan' => 'vip']), escape: false)
        // The card reads brand, name and price from the product row.
        ->assertSee('Mango')
        ->assertSee('Silk office blouse')
        ->assertSee('320.00 AED')
        ->assertSee('background: #1B2A4A;', escape: false)
        // Buying goes through the click-counting redirect, never a raw link.
        ->assertSee(route('membership.account.shop', ['product' => $top->id, 'from' => 'capsule']), escape: false)
        ->assertSee(route('membership.account.shop', ['product' => $shoes->id, 'from' => 'capsule']), escape: false)
        // Trying on opens the dashboard with the garment picked; shoes cannot be tried on.
        ->assertSee(route('membership.account.dashboard', ['pick' => $top->id, 'plan' => 'vip']).'#catalog-grid')
        ->assertDontSee(route('membership.account.dashboard', ['pick' => $shoes->id, 'plan' => 'vip']))
        ->assertSee('Примірка цієї речі поки недоступна')
        // The look, its slots and why it suits, with the palette match.
        ->assertSee('id="capsule-ways"', escape: false)
        ->assertSee('Образ 1')
        ->assertSee('Взуття')
        ->assertSee('Глибока зима')
        ->assertSee('Збіг з палітрою — 80%');
});

it('marks the pieces the member has already hearted', function () {
    $member = capsuleMember();
    $top = capsuleCatalogPiece('upper_body', 'office');
    capsuleCatalogPiece('lower_body');
    capsuleCatalogPiece('shoes');
    Favorite::factory()->for($member)->for($top)->create();

    $this->actingAs($member)->get('/account/capsules/office?plan=vip')->assertOk()
        ->assertSee('favorites: ['.$top->id.']', escape: false)
        ->assertSee(json_encode(route('membership.account.favorites.store', ['product' => 0])), escape: false);
});

it('says which slot is missing instead of an empty capsule', function () {
    capsuleCatalogPiece('upper_body');
    capsuleCatalogPiece('lower_body');

    $this->actingAs(capsuleMember())->get('/account/capsules/casual?plan=vip')->assertOk()
        ->assertSee('id="capsule-empty"', escape: false)
        ->assertSee('у розділі «Взуття»', escape: false)
        ->assertDontSee('id="capsule-pieces"', escape: false);
});

it('says when no piece is made for the occasion', function () {
    capsuleCatalogPiece('upper_body');
    capsuleCatalogPiece('lower_body');
    capsuleCatalogPiece('shoes');

    $this->actingAs(capsuleMember())->get('/account/capsules/evening?plan=vip')->assertOk()
        ->assertSee('створених саме для вечора', escape: false);
});

it('knows only the configured occasions', function () {
    $this->actingAs(capsuleMember())->get('/account/capsules/beach?plan=vip')->assertNotFound();
});

it('asks for the colour analysis when the member has no season', function () {
    capsuleCatalogPiece('upper_body');
    $member = capsuleMember(null);

    $this->actingAs($member)->get('/account/capsules?plan=vip')->assertOk()
        ->assertSee('id="capsules-no-season"', escape: false)
        ->assertSee(route('membership.account.color'), escape: false)
        ->assertDontSee('id="capsule-cards"', escape: false);

    $this->actingAs($member)->get('/account/capsules/office?plan=vip')
        ->assertRedirect(route('membership.account.capsules', ['plan' => 'vip']));
});

it('builds no looks while the colour analysis is off', function () {
    config(['features.color_analysis' => false]);

    $this->actingAs(capsuleMember())->get('/account/capsules?plan=vip')->assertOk()
        ->assertSee('id="capsules-colour-off"', escape: false)
        ->assertDontSee('id="capsule-cards"', escape: false);
});

it('keeps capsules behind the plan lock', function () {
    capsuleCatalogPiece('upper_body');
    $member = capsuleMember();

    $this->actingAs($member)->get('/account/capsules?plan=fashionista')->assertOk()
        ->assertSee('id="capsules-locked"', escape: false)
        ->assertDontSee('id="capsule-cards"', escape: false);

    $this->actingAs($member)->get('/account/capsules/office?plan=fashionista')
        ->assertRedirect(route('membership.account.capsules', ['plan' => 'fashionista']));
});

it('counts a capsule shop click with its own source', function () {
    $member = capsuleMember();
    $shoes = capsuleCatalogPiece('shoes', attributes: ['shop_url' => 'https://www.amazon.ae/dp/B0SHOES?tag=example-21']);

    $this->actingAs($member)->get(route('membership.account.shop', ['product' => $shoes->id, 'from' => 'capsule']))
        ->assertRedirect('https://www.amazon.ae/dp/B0SHOES?tag=example-21');

    expect(ProductClick::sole()->source)->toBe('capsule');
});
