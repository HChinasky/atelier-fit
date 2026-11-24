<?php

use App\Models\ColorProfile;
use App\Models\Product;
use App\Models\ProductClick;
use App\Models\ProductSeasonMatch;
use App\Models\StyleProfile;
use App\Models\User;
use App\Services\Color\ColorRecommendations;
use App\Services\Color\MemberColorPresenter;

/*
 * The shelf: catalog pieces in the member's season, narrowed by their style
 * quiz, and the buy link that counts the click on the way to the shop.
 */

beforeEach(function () {
    config(['features.membership' => true, 'features.color_analysis' => true]);
});

/** A verified product that suits (or not) a season, at a given score. */
function shelfProduct(string $season, float $score = 0.5, array $attributes = [], bool $suits = true): Product
{
    $product = Product::factory()->verified()->create($attributes);
    ProductSeasonMatch::factory()->for($product)->forSeason($season)
        ->{$suits ? 'forSeason' : 'clashing'}($season)
        ->create(['score' => $suits ? $score : 0.0]);

    return $product;
}

function shelf(string $season, ?array $answers = null): array
{
    $found = app(ColorRecommendations::class)->forSeason($season, $answers);

    return ['ids' => $found['products']->pluck('id')->all(), 'filtered_by' => $found['filtered_by']];
}

// --- what is on the shelf -------------------------------------------------------

it('shows only verified pieces that suit the season, best match first', function () {
    $good = shelfProduct('soft_summer', 0.9);
    $fair = shelfProduct('soft_summer', 0.4);
    shelfProduct('soft_summer', suits: false);
    shelfProduct('deep_winter', 0.95);
    $unverified = Product::factory()->create();
    ProductSeasonMatch::factory()->for($unverified)->forSeason('soft_summer')->create(['score' => 1.0]);

    expect(shelf('soft_summer'))->toBe(['ids' => [$good->id, $fair->id], 'filtered_by' => []]);
});

it('narrows to the categories and budget from the style quiz', function () {
    $dressInBudget = shelfProduct('soft_summer', 0.5, ['category' => 'dresses', 'price_aed' => '350.00']);
    shelfProduct('soft_summer', 0.9, ['category' => 'dresses', 'price_aed' => '1200.00']);
    shelfProduct('soft_summer', 0.9, ['category' => 'lower_body', 'price_aed' => '300.00']);

    expect(shelf('soft_summer', ['categories' => ['dresses'], 'budget' => '200_500']))
        ->toBe(['ids' => [$dressInBudget->id], 'filtered_by' => ['categories', 'budget']]);
});

it('drops the budget, then the categories, rather than show an empty shelf', function () {
    $dear = shelfProduct('soft_summer', 0.9, ['category' => 'dresses', 'price_aed' => '1200.00']);
    $trousers = shelfProduct('soft_summer', 0.5, ['category' => 'lower_body', 'price_aed' => '1500.00']);

    expect(shelf('soft_summer', ['categories' => ['dresses'], 'budget' => 'under_200']))
        ->toBe(['ids' => [$dear->id], 'filtered_by' => ['categories']])
        ->and(shelf('soft_summer', ['categories' => ['upper_body'], 'budget' => 'under_200']))
        ->toBe(['ids' => [$dear->id, $trousers->id], 'filtered_by' => []]);
});

it('gives the member\'s season its shelf, with buy and try-on links', function () {
    seedPalettes();
    $member = User::factory()->emailOnly()->create();
    StyleProfile::factory()->for($member)->create(['answers' => ['categories' => ['dresses']]]);
    $dress = shelfProduct('deep_winter', 0.8, ['category' => 'dresses', 'brand_name' => 'Ounass', 'price_aed' => '420.00']);
    $profile = ColorProfile::factory()->classified('deep_winter')->create(['user_id' => $member->id]);

    $json = app(MemberColorPresenter::class)->present($profile);

    expect($json['products'])->toHaveCount(1)
        ->and($json['products'][0])->toMatchArray([
            'id' => $dress->id,
            'brand' => 'Ounass',
            'price' => '420.00 AED',
            'buy_url' => route('membership.account.shop', ['product' => $dress->id, 'from' => 'color']),
            'try_url' => route('membership.account.dashboard', ['pick' => $dress->id]).'#catalog-grid',
        ])
        ->and($json['products_filtered_by'])->toBe(['categories']);
});

it('gives a profile without a season no shelf', function () {
    $profile = ColorProfile::factory()->rejected()->create();

    expect(app(MemberColorPresenter::class)->present($profile)['products'])->toBe([]);
});

// --- the page -----------------------------------------------------------------

it('draws the shelf on the colour sheet, and says so when it is empty', function () {
    seedPalettes();
    $member = User::factory()->emailOnly()->onboarded()->create();
    ColorProfile::factory()->classified('deep_winter')->create(['user_id' => $member->id]);

    $this->actingAs($member)->get('/account/color')->assertOk()
        ->assertSee('id="color-shelf"', escape: false)
        ->assertSee('Речі у твоїх кольорах')
        ->assertSee('id="color-shelf-empty"', escape: false);
});

// --- the dashboard --------------------------------------------------------------

it('offers a "Мої кольори" chip in the catalog once the member has a season with pieces', function () {
    $member = User::factory()->emailOnly()->onboarded()->create();
    $profile = ColorProfile::factory()->classified('deep_winter')->create(['user_id' => $member->id]);
    $member->forceFill(['color_profile_id' => $profile->id])->save();
    $piece = shelfProduct('deep_winter');

    $this->actingAs($member)->get('/account')->assertOk()
        ->assertSee('id="chip-my-colours"', escape: false)
        ->assertSee("myColours: JSON.parse('[".$piece->id."]')", escape: false);
});

it('has no "Мої кольори" chip without a season', function () {
    shelfProduct('deep_winter');

    $this->actingAs(User::factory()->emailOnly()->onboarded()->create())->get('/account')->assertOk()
        ->assertDontSee('id="chip-my-colours"', escape: false);
});

it('opens the dashboard with a piece picked from the colour page', function () {
    $piece = shelfProduct('deep_winter');
    $member = User::factory()->emailOnly()->onboarded()->create();

    $this->actingAs($member)->get('/account?pick='.$piece->id)->assertOk()
        ->assertSee('selected: '.$piece->id, escape: false);
    $this->actingAs($member)->get('/account?pick=999999')->assertOk()
        ->assertSee('selected: null', escape: false);
});

// --- the buy link -----------------------------------------------------------------

it('counts the click with its source and the member\'s season, then leaves for the shop', function () {
    $member = User::factory()->emailOnly()->onboarded()->create();
    $profile = ColorProfile::factory()->classified('deep_winter')->create(['user_id' => $member->id]);
    $member->forceFill(['color_profile_id' => $profile->id])->save();
    $product = Product::factory()->verified()->create(['shop_url' => 'https://shop.example/dress']);

    $this->actingAs($member)->get('/account/shop/'.$product->id.'?from=color')
        ->assertRedirect('https://shop.example/dress');

    expect(ProductClick::sole())->toMatchArray([
        'user_id' => $member->id,
        'product_id' => $product->id,
        'source' => 'color',
        'season' => 'deep_winter',
    ]);
});

it('files a click from anywhere unknown under the catalog', function () {
    $product = Product::factory()->verified()->create(['shop_url' => 'https://shop.example/x']);

    $this->actingAs(User::factory()->emailOnly()->onboarded()->create())->get('/account/shop/'.$product->id.'?from=evil')
        ->assertRedirect('https://shop.example/x');

    expect(ProductClick::sole()->source)->toBe('catalog')
        ->and(ProductClick::sole()->season)->toBeNull();
});

it('refuses to redirect anywhere but an http(s) shop link', function () {
    $product = Product::factory()->verified()->create(['shop_url' => 'javascript:alert(1)']);

    $this->actingAs(User::factory()->emailOnly()->onboarded()->create())->get('/account/shop/'.$product->id)->assertNotFound();

    expect(ProductClick::count())->toBe(0);
});

it('keeps the buy link behind sign-in and the membership flag', function () {
    $product = Product::factory()->verified()->create();

    $this->get('/account/shop/'.$product->id)->assertRedirect(route('login'));

    config(['features.membership' => false]);
    $this->actingAs(User::factory()->emailOnly()->onboarded()->create())->get('/account/shop/'.$product->id)->assertNotFound();
});
