<?php

use App\Models\Favorite;
use App\Models\Product;
use App\Models\User;

/*
 * "Обране": one server-side list behind every heart on the account — the
 * catalog card, the chosen-piece panel, the detail modal and the wardrobe.
 */

beforeEach(function () {
    config(['features.membership' => true]);
});

function favMember(): User
{
    return User::factory()->emailOnly()->onboarded()->create();
}

it('hearts a piece, idempotently', function () {
    $user = favMember();
    $product = Product::factory()->verified()->create();

    $this->actingAs($user)->postJson("/account/favorites/{$product->id}")->assertOk()->assertJson(['liked' => true]);
    $this->actingAs($user)->postJson("/account/favorites/{$product->id}")->assertOk();

    expect(Favorite::where('user_id', $user->id)->pluck('product_id')->all())->toBe([$product->id]);
});

it('un-hearts a piece, idempotently, and only for the member who asks', function () {
    $user = favMember();
    $other = favMember();
    $product = Product::factory()->verified()->create();
    Favorite::factory()->for($user)->for($product)->create();
    Favorite::factory()->for($other)->for($product)->create();

    $this->actingAs($user)->deleteJson("/account/favorites/{$product->id}")->assertOk()->assertJson(['liked' => false]);
    $this->actingAs($user)->deleteJson("/account/favorites/{$product->id}")->assertOk();

    expect(Favorite::where('user_id', $user->id)->exists())->toBeFalse()
        ->and(Favorite::where('user_id', $other->id)->exists())->toBeTrue();
});

it('404s an unknown piece', function () {
    $this->actingAs(favMember())->postJson('/account/favorites/999999')->assertNotFound();
});

it('keeps hearts behind sign-in', function () {
    $product = Product::factory()->verified()->create();
    $this->post("/account/favorites/{$product->id}")->assertRedirect(route('login'));
    expect(Favorite::count())->toBe(0);
});

it('opens the dashboard with the member\'s hearts from the account, not the browser', function () {
    $user = favMember();
    $liked = Product::factory()->verified()->create();
    Product::factory()->verified()->create();
    Favorite::factory()->for($user)->for($liked)->create();

    $this->actingAs($user)->get('/account')
        ->assertSee('liked: '.json_encode([$liked->id]), escape: false)
        ->assertSee(json_encode(route('membership.account.favorites.store', ['product' => 0])), escape: false)
        ->assertSee('x-on:click.stop="toggleLike('.$liked->id.')"', escape: false);
});

it('lists every hearted piece under "Обране" in the wardrobe, tried on or not', function () {
    $user = favMember();
    $hearted = Product::factory()->verified()->create(['product_name' => 'Сукня з каталогу']);
    $notHearted = Product::factory()->verified()->create(['product_name' => 'Не в обраному']);
    Favorite::factory()->for($user)->for($hearted)->create();

    $this->actingAs($user)->get('/account/wardrobe?plan=vip')
        ->assertOk()
        ->assertSee('id="favorites-list"', escape: false)
        ->assertSee('id="fav-'.$hearted->id.'"', escape: false)
        ->assertSee('Сукня з каталогу')
        ->assertDontSee('Не в обраному')
        ->assertSee('favorites: '.json_encode([$hearted->id]), escape: false);
});

it('drops the hearts with the account', function () {
    $user = favMember();
    Favorite::factory()->for($user)->create();

    $user->delete();

    expect(Favorite::count())->toBe(0);
});
