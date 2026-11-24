<?php

use App\Models\Generation;
use App\Models\Lead;
use App\Models\Product;
use App\Models\User;

/*
 * "Видалити цю добірку" and the per-piece close are kept: they hide the set or
 * the piece for good, while the generation row stays for the rate limit and
 * the credit ledger.
 */

beforeEach(function () {
    config(['features.membership' => true]);
});

/** A member with a finished outfit made of the given pieces. */
function memberWithOutfit(int $pieces = 2): array
{
    $user = User::factory()->emailOnly()->create();
    $products = Product::factory()->verified()->count($pieces)->create();
    $generation = Generation::factory()->done()->create([
        'lead_id' => Lead::factory()->forUser($user)->create()->id,
        'product_id' => $products->first()->id,
        'product_ids' => $products->pluck('id')->all(),
    ]);

    return [$user, $products, $generation];
}

it('keeps a removed set out of the wardrobe without erasing the generation', function () {
    [$user, , $generation] = memberWithOutfit();

    $this->actingAs($user)->deleteJson("/account/wardrobe/looks/{$generation->id}")
        ->assertOk()->assertJson(['hidden' => true]);
    $this->actingAs($user)->deleteJson("/account/wardrobe/looks/{$generation->id}")->assertOk();

    expect($generation->fresh())->not->toBeNull()
        ->and($generation->fresh()->wardrobe_hidden_at)->not->toBeNull();

    $this->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertDontSee('id="look-'.$generation->id.'"', escape: false)
        ->assertSee('id="wardrobe-empty"', escape: false);
});

it('keeps a removed piece out of its set only', function () {
    [$user, $products, $generation] = memberWithOutfit();
    [$gone, $kept] = $products->all();

    // The same piece in a second set stays there.
    $other = Generation::factory()->done()->create([
        'lead_id' => $generation->lead_id,
        'product_id' => $gone->id,
    ]);

    $this->actingAs($user)->deleteJson("/account/wardrobe/looks/{$generation->id}/pieces/{$gone->id}")->assertOk();
    $this->actingAs($user)->deleteJson("/account/wardrobe/looks/{$generation->id}/pieces/{$gone->id}")->assertOk();

    expect($generation->fresh()->wardrobe_hidden_product_ids)->toBe([$gone->id]);

    $this->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertSee('id="look-'.$generation->id.'"', escape: false)
        ->assertDontSee('id="piece-'.$generation->id.'-'.$gone->id.'"', escape: false)
        ->assertSee('id="piece-'.$generation->id.'-'.$kept->id.'"', escape: false)
        ->assertSee('id="piece-'.$other->id.'-'.$gone->id.'"', escape: false);
});

it('drops a set once every piece of it was taken out', function () {
    [$user, $products] = memberWithOutfit();
    $generation = Generation::factory()->done()->withHiddenPieces($products->pluck('id')->all())->create([
        'lead_id' => Lead::factory()->forUser($user)->create()->id,
        'product_id' => $products->first()->id,
        'product_ids' => $products->pluck('id')->all(),
    ]);

    $this->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertDontSee('id="look-'.$generation->id.'"', escape: false);
});

it('404s somebody else\'s set and a piece the set was not made of', function () {
    [, , $generation] = memberWithOutfit();
    $stranger = User::factory()->emailOnly()->create();

    $this->actingAs($stranger)->deleteJson("/account/wardrobe/looks/{$generation->id}")->assertNotFound();
    $this->actingAs($stranger)->deleteJson("/account/wardrobe/looks/{$generation->id}/pieces/{$generation->product_id}")->assertNotFound();
    expect($generation->fresh()->wardrobe_hidden_at)->toBeNull();
});

it('404s a piece that is not part of the member\'s own set', function () {
    [$user, , $generation] = memberWithOutfit();
    $unrelated = Product::factory()->verified()->create();

    $this->actingAs($user)->deleteJson("/account/wardrobe/looks/{$generation->id}/pieces/{$unrelated->id}")->assertNotFound();
    expect($generation->fresh()->wardrobe_hidden_product_ids)->toBeNull();
});

it('keeps hiding behind sign-in', function () {
    [, , $generation] = memberWithOutfit();

    $this->delete("/account/wardrobe/looks/{$generation->id}")->assertRedirect(route('login'));
    expect($generation->fresh()->wardrobe_hidden_at)->toBeNull();
});

it('wires the remove buttons to the hide routes, per set and piece', function () {
    [$user, $products, $generation] = memberWithOutfit(1);

    $this->actingAs($user)->get('/account/wardrobe?plan=couture')
        ->assertSee('x-on:click="removeLook('.$generation->id.')"', escape: false)
        ->assertSee('x-on:click="removePiece('.$generation->id.', '.$products->first()->id.')"', escape: false)
        ->assertSee(json_encode(route('membership.account.wardrobe.looks.hide', ['generation' => 0])), escape: false);
});
