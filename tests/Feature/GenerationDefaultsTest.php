<?php

use App\Models\Generation;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

/*
 * Generation defaults must agree across all three sources of truth — the DB
 * column default, the factory, and config('services.fashn.num_samples') used by
 * the API. Phase 5 set the modest look count to 2, so a Generation built directly
 * (no API) gets the same num_samples as one created through the endpoint.
 */

it('defaults num_samples to 2 and with_background to false via the factory', function () {
    $generation = Generation::factory()->create();

    expect($generation->num_samples)->toBe(2)
        ->and($generation->with_background)->toBeFalse();
});

it('defaults num_samples to 2 at the database column level', function () {
    // Raw insert (deliberately bypassing the model/factory) so the DB column
    // defaults apply on their own — this proves the migration agrees with the
    // factory and config, not just that the factory sets the value.
    $product = Product::factory()->create();

    $id = DB::table('generations')->insertGetId([
        'product_id' => $product->id,
        'client_photo' => 'uploads/2026-06-12/abc.jpg',
        'status' => 'queued',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $generation = Generation::findOrFail($id);

    expect($generation->num_samples)->toBe(2)
        ->and($generation->with_background)->toBeFalse();
});
