<?php

use App\Models\Lead;
use App\Models\PhotoConsent;
use App\Models\Product;
use App\Models\ProductSeasonMatch;
use App\Models\User;
use App\Services\Color\ColorRecommendations;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Shoes and headwear are in the catalog for capsules only: the try-on engine
 * cannot dress them, so no try-on surface may offer them and no generation
 * endpoint may accept them.
 */

it('keeps shoes and headwear out of the try-on catalog', function () {
    $top = Product::factory()->verified()->upperBody()->create();
    Product::factory()->verified()->shoes()->create();
    Product::factory()->verified()->headwear()->create();

    $this->getJson('/api/catalog')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $top->id);
});

it('keeps shoes and headwear off the dashboard catalog', function () {
    config(['features.membership' => true]);
    Product::factory()->verified()->upperBody()->create(['product_name' => 'Linen top']);
    Product::factory()->verified()->shoes()->create(['product_name' => 'Suede loafers']);

    $this->actingAs(User::factory()->emailOnly()->onboarded()->create())
        ->get('/account')->assertOk()
        ->assertSee('Linen top')
        ->assertDontSee('Suede loafers');
});

it('keeps shoes out of the my-colours shelf, which offers try-on', function () {
    $top = Product::factory()->verified()->upperBody()->create();
    $shoes = Product::factory()->verified()->shoes()->create();
    foreach ([$top, $shoes] as $product) {
        ProductSeasonMatch::factory()->for($product)->forSeason('deep_winter')->neutral()->create();
    }

    expect(app(ColorRecommendations::class)->productIds('deep_winter'))->toBe([$top->id]);
});

it('refuses to generate a try-on of shoes', function () {
    Storage::fake('r2');
    Queue::fake();

    $this->postJson('/api/fitting-room/generate', [
        'lead_id' => Lead::factory()->create()->id,
        'product_id' => Product::factory()->verified()->shoes()->create()->id,
        'photo_key' => 'uploads/2026-10-09/abc.jpg',
        'consent_id' => PhotoConsent::factory()->create()->id,
    ])->assertStatus(422)->assertJsonValidationErrors('product_id');

    Queue::assertNothingPushed();
});

it('refuses headwear in an outfit set', function () {
    Storage::fake('r2');
    Queue::fake();

    $this->postJson('/api/fitting-room/generate-outfit', [
        'lead_id' => Lead::factory()->create()->id,
        'product_ids' => [
            Product::factory()->verified()->upperBody()->create()->id,
            Product::factory()->verified()->headwear()->create()->id,
        ],
        'photo_key' => 'uploads/2026-10-09/abc.jpg',
        'consent_id' => PhotoConsent::factory()->create()->id,
    ])->assertStatus(422)->assertJsonValidationErrors('product_ids.1');

    Queue::assertNothingPushed();
});
