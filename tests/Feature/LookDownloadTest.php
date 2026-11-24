<?php

/*
 * "Зберегти образ" under a finished try-on: the look as a file, served from
 * our own storage, and only ever to the member whose lead made it. The
 * try-on's "Купити в магазині" goes through the shop click counter, filed
 * under its own source.
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
    config(['fitting_room.photo.disk' => 'r2']);
});

/** A member and a finished look of theirs with its image in storage. */
function memberLookInStorage(): array
{
    $user = User::factory()->emailOnly()->onboarded()->create();
    $generation = Generation::factory()->done()->create([
        'lead_id' => Lead::factory()->forUser($user)->create()->id,
        'result_keys' => ['results/look.png'],
    ]);
    Storage::disk('r2')->put('results/look.png', 'png-bytes');

    return [$user, $generation];
}

it('hands the member their own look as a download', function () {
    [$user, $generation] = memberLookInStorage();

    $response = test()->actingAs($user)->get(route('membership.account.looks.download', $generation))
        ->assertOk()
        ->assertDownload("atelier-fit-look-{$generation->id}.png");

    expect($response->streamedContent())->toBe('png-bytes')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('does not hand a look to another member', function () {
    [, $generation] = memberLookInStorage();

    test()->actingAs(User::factory()->emailOnly()->onboarded()->create())
        ->get(route('membership.account.looks.download', $generation))
        ->assertNotFound();
});

it('has nothing to download before the look is finished or after its image expired', function () {
    [$user, $generation] = memberLookInStorage();

    $generation->update(['status' => 'processing']);
    test()->actingAs($user)->get(route('membership.account.looks.download', $generation))->assertNotFound();

    $generation->update(['status' => 'done']);
    Storage::disk('r2')->delete('results/look.png');
    test()->actingAs($user)->get(route('membership.account.looks.download', $generation))->assertNotFound();

    $generation->update(['result_keys' => null]);
    test()->actingAs($user)->get(route('membership.account.looks.download', $generation))->assertNotFound();
});

it('keeps the download behind sign-in and the membership flag', function () {
    [$user, $generation] = memberLookInStorage();

    test()->get(route('membership.account.looks.download', $generation))->assertRedirect(route('login'));

    config(['features.membership' => false]);
    test()->actingAs($user)->get('/account/looks/'.$generation->id.'/download')->assertNotFound();
});

it('files the try-on\'s buy button under its own source', function () {
    $product = Product::factory()->verified()->create(['shop_url' => 'https://shop.example/blazer']);

    test()->actingAs(User::factory()->emailOnly()->onboarded()->create())
        ->get(route('membership.account.shop', ['product' => $product->id, 'from' => 'tryon']))
        ->assertRedirect('https://shop.example/blazer');

    expect(ProductClick::sole()->source)->toBe('tryon');
});
