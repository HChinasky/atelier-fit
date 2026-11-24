<?php

use App\Models\Generation;
use App\Models\Lead;
use App\Models\PhotoConsent;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * The guest side of the "2+4" funnel (Stage B3, FEATURE_MEMBERSHIP on): a
 * contact gets `membership.guest_free_generations` try-ons for free — lifetime,
 * across all its leads, failed ones excluded — then the API answers 403 with
 * code=signup_required so the page can steer into the sign-in. With the
 * flag OFF nothing changes: the flood guard (3/day) stays the only limit.
 */

beforeEach(function () {
    config(['features.membership' => true, 'membership.guest_free_generations' => 2]);
    Storage::fake('r2');
    Queue::fake();
    Http::fake();
});

function funnelPayload(Lead $lead): array
{
    return [
        'lead_id' => $lead->id,
        'product_id' => Product::factory()->create()->id,
        'photo_key' => 'uploads/2026-06-12/abc.jpg',
        'consent_id' => PhotoConsent::factory()->create()->id,
    ];
}

it('lets a guest contact use its free try-ons, then requires signup', function () {
    $lead = Lead::factory()->create();

    $this->postJson('/api/fitting-room/generate', funnelPayload($lead))->assertStatus(202);
    $this->postJson('/api/fitting-room/generate', funnelPayload($lead))->assertStatus(202);

    $this->postJson('/api/fitting-room/generate', funnelPayload($lead))
        ->assertStatus(403)
        ->assertJsonPath('code', 'signup_required')
        ->assertJsonPath('message', fn (string $m) => str_contains($m, 'Увійдіть'));

    expect(Generation::count())->toBe(2);
});

it('counts the allowance per CONTACT, not per lead — a re-submitted form does not reset it', function () {
    $first = Lead::factory()->create(['contact' => 'shopper@example.ae']);
    $again = Lead::factory()->create(['contact' => '  Shopper@Example.AE ']); // same human, new form

    $this->postJson('/api/fitting-room/generate', funnelPayload($first))->assertStatus(202);
    $this->postJson('/api/fitting-room/generate', funnelPayload($first))->assertStatus(202);

    $this->postJson('/api/fitting-room/generate', funnelPayload($again))
        ->assertStatus(403)
        ->assertJsonPath('code', 'signup_required');
});

it('gives a different contact its own fresh allowance', function () {
    $spent = Lead::factory()->create(['contact' => 'used-up@example.ae']);
    Generation::factory()->count(2)->create(['lead_id' => $spent->id]);

    $fresh = Lead::factory()->create(['contact' => 'newcomer@example.ae']);

    $this->postJson('/api/fitting-room/generate', funnelPayload($fresh))->assertStatus(202);
});

it('does not count failed generations against the free allowance', function () {
    $lead = Lead::factory()->create();
    Generation::factory()->create(['lead_id' => $lead->id, 'status' => 'done']);
    Generation::factory()->create(['lead_id' => $lead->id, 'status' => 'failed']);

    // 1 counted + 1 failed → one free try still available.
    $this->postJson('/api/fitting-room/generate', funnelPayload($lead))->assertStatus(202);
    $this->postJson('/api/fitting-room/generate', funnelPayload($lead))
        ->assertStatus(403)
        ->assertJsonPath('code', 'signup_required');
});

it('refuses an outfit whose units do not fit the remaining allowance, atomically', function () {
    $lead = Lead::factory()->create();
    Generation::factory()->create(['lead_id' => $lead->id, 'status' => 'done']);

    // 1 used of 2 — a 2-garment outfit (2 units) must not squeeze through.
    $upper = Product::factory()->create(['category' => 'upper_body']);
    $lower = Product::factory()->create(['category' => 'lower_body']);

    $this->postJson('/api/fitting-room/generate-outfit', [
        'lead_id' => $lead->id,
        'product_ids' => [$upper->id, $lower->id],
        'photo_key' => 'uploads/2026-06-12/abc.jpg',
        'consent_id' => PhotoConsent::factory()->create()->id,
    ])
        ->assertStatus(403)
        ->assertJsonPath('code', 'signup_required');

    expect(Generation::count())->toBe(1); // nothing new was claimed
});

it('changes nothing while the membership flag is off — the flood guard stays the only limit', function () {
    config(['features.membership' => false, 'fitting_room.generation.per_user_daily_limit' => 3]);

    $lead = Lead::factory()->create();

    // A third try-on (over the funnel's 2, within the daily 3) still passes...
    $this->postJson('/api/fitting-room/generate', funnelPayload($lead))->assertStatus(202);
    $this->postJson('/api/fitting-room/generate', funnelPayload($lead))->assertStatus(202);
    $this->postJson('/api/fitting-room/generate', funnelPayload($lead))->assertStatus(202);

    // ...and the fourth hits the flood guard's 429, not a funnel 403.
    $this->postJson('/api/fitting-room/generate', funnelPayload($lead))
        ->assertStatus(429)
        ->assertJsonMissingPath('code');
});
