<?php

/*
 * "My Looks": the member's own finished try-ons, newest first — no mock
 * cards. Save downloads the stored image, Shop goes through the click log
 * to the first piece's shop.
 */

use App\Models\Generation;
use App\Models\Lead;
use App\Models\Product;
use App\Models\ProductClick;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    config(['features.membership' => true]);
    Storage::fake('r2');
});

/** A finished try-on of $product by $user. */
function lookOf(User $user, Product $product, array $attributes = []): Generation
{
    return Generation::factory()->done()->create([
        'lead_id' => Lead::factory()->forUser($user)->create()->id,
        'product_id' => $product->id,
    ] + $attributes);
}

it('shows the member\'s own looks with their images, newest first', function () {
    $user = User::factory()->emailOnly()->create();
    $old = lookOf($user, Product::factory()->verified()->brand('Mango')->create(['product_name' => 'Older Blazer']));
    $new = lookOf($user, Product::factory()->verified()->brand('Zara')->create(['product_name' => 'Newer Dress']));

    test()->actingAs($user)->get('/account/looks')->assertOk()
        ->assertSee('id="looks-grid"', escape: false)
        ->assertSeeInOrder(['Zara', 'Newer Dress', 'Mango', 'Older Blazer'])
        ->assertSee('src="'.$new->result_urls[0].'"', escape: false)
        ->assertSee('src="'.$old->result_urls[0].'"', escape: false)
        ->assertDontSee('Linen-Blend Blazer')
        ->assertDontSee('looks-empty');
});

it('lets a look take its image\'s own proportions, whole and uncropped', function () {
    $user = User::factory()->emailOnly()->create();
    lookOf($user, Product::factory()->verified()->create());

    // No fixed 3:4 box around a real image: a box of another shape either
    // crops it (cover) or leaves bands at the sides (contain).
    test()->actingAs($user)->get('/account/looks')
        ->assertSee('class="look-media has-image"', escape: false)
        ->assertSee('.look-media.has-image { aspect-ratio: auto; }', escape: false)
        ->assertSee('.look-media img { display: block; width: 100%; height: auto; }', escape: false);
});

it('never shows another member\'s looks or unfinished ones', function () {
    $user = User::factory()->emailOnly()->create();
    lookOf(User::factory()->emailOnly()->create(), Product::factory()->verified()->create(['product_name' => 'Someone Else']));
    Generation::factory()->failed()->create([
        'lead_id' => Lead::factory()->forUser($user)->create()->id,
        'product_id' => Product::factory()->verified()->create(['product_name' => 'Failed Try'])->id,
    ]);

    test()->actingAs($user)->get('/account/looks')->assertOk()
        ->assertDontSee('Someone Else')
        ->assertDontSee('Failed Try')
        ->assertSee('id="looks-empty"', escape: false)
        ->assertSee(__('pages.looks.empty_title'))
        ->assertSee('href="'.route('membership.account.dashboard').'"', escape: false);
});

it('links Shop through the click log and Save to the download', function () {
    $user = User::factory()->emailOnly()->create();
    $product = Product::factory()->verified()->create(['shop_url' => 'https://shop.example/item']);
    $look = lookOf($user, $product, ['result_keys' => ['results/look.png']]);
    Storage::disk('r2')->put('results/look.png', 'png');

    $shop = route('membership.account.shop', ['product' => $product->id, 'from' => 'looks']);

    test()->actingAs($user)->get('/account/looks')
        ->assertSee('href="'.e($shop).'"', escape: false)
        ->assertSee('href="'.route('membership.account.looks.download', ['generation' => $look->id]).'"', escape: false);

    test()->actingAs($user)->get($shop)->assertRedirect('https://shop.example/item');
    expect(ProductClick::where('product_id', $product->id)->value('source'))->toBe('looks');
});

it('offers no Save for a look with no stored copy and no Shop without a shop link', function () {
    $user = User::factory()->emailOnly()->create();
    $look = lookOf($user, Product::factory()->verified()->create(['shop_url' => '']));

    test()->actingAs($user)->get('/account/looks')
        ->assertSee('data-look="'.$look->id.'"', escape: false)
        ->assertDontSee('/download', escape: false)
        ->assertDontSee('class="shop"', escape: false);
});

it('keeps a look whose image has expired, with a note instead of a broken image', function () {
    $user = User::factory()->emailOnly()->create();
    $look = lookOf($user, Product::factory()->verified()->create());
    $look->update(['result_urls' => null]);

    test()->actingAs($user)->get('/account/looks')
        ->assertSee(__('pages.looks.image_expired'))
        ->assertDontSee('<img src=', escape: false);
});

it('counts the other pieces of an outfit', function () {
    $user = User::factory()->emailOnly()->create();
    $a = Product::factory()->verified()->create(['product_name' => 'Base Top']);
    $b = Product::factory()->verified()->create();
    $c = Product::factory()->verified()->create();
    Generation::factory()->done()->outfit([$a->id, $b->id, $c->id])->create([
        'lead_id' => Lead::factory()->forUser($user)->create()->id,
    ]);

    test()->actingAs($user)->get('/account/looks')
        ->assertSee('Base Top')
        ->assertSee(__('pages.looks.more_pieces', ['count' => 2]));
});
