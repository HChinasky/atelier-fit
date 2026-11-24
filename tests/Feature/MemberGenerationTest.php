<?php

use App\Jobs\GenerateOutfitJob;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\Lead;
use App\Models\PhotoConsent;
use App\Models\Product;
use App\Models\User;
use App\Services\Credits\CreditService;
use App\Services\Generation\LeadToken;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

/*
 * The member side of the "2+4" funnel (Stage B3): a logged-in member's page
 * render mints an OWNED lead + ownership token; generations with it debit the
 * credit ledger (skipping the guest flood guard), an empty ledger answers 402
 * with code=credits_exhausted, and a failed generation refunds its credit.
 * The ownership token is a security boundary: a member lead_id without proof
 * must be worth nothing to an enumerator.
 */

beforeEach(function () {
    config(['features.membership' => true]);
    Storage::fake('r2');
});

/** A member with a welcome-sized ledger and their owned lead (+ valid token). */
function memberWithLead(int $credits = 4): array
{
    $user = User::factory()->emailOnly()->create();
    if ($credits > 0) {
        CreditTransaction::factory()->for($user)->create(['delta' => $credits]);
    }
    $lead = Lead::factory()->forUser($user)->create();

    return [$user, $lead, app(LeadToken::class)->make($lead)];
}

function memberPayload(Lead $lead, string $token): array
{
    return [
        'lead_id' => $lead->id,
        'lead_token' => $token,
        'product_id' => Product::factory()->create()->id,
        'photo_key' => 'uploads/2026-06-12/abc.jpg',
        'consent_id' => PhotoConsent::factory()->create()->id,
    ];
}

// ---- the page mints the member lead ----

it('mints an owned lead and its token on the page for a logged-in member', function () {
    $user = User::factory()->emailOnly()->create();

    $response = $this->actingAs($user)->get('/ai-fitting-room')->assertOk();

    $lead = Lead::sole();
    expect($lead->user_id)->toBe($user->id)
        ->and($lead->contact)->toBe($user->email)
        ->and($lead->tags)->toContain('member');

    $response->assertSee((string) app(LeadToken::class)->make($lead), escape: false);
});

it('reuses the same owned lead on repeat visits', function () {
    $user = User::factory()->emailOnly()->create();

    $this->actingAs($user)->get('/ai-fitting-room')->assertOk();
    $this->actingAs($user)->get('/ai-fitting-room')->assertOk();

    expect(Lead::count())->toBe(1);
});

it('mints nothing for guests or while the flag is off', function () {
    $this->get('/ai-fitting-room')->assertOk()->assertSee('memberLead: null', escape: false);

    config(['features.membership' => false]);
    $this->actingAs(User::factory()->emailOnly()->create())
        ->get('/ai-fitting-room')->assertOk()->assertSee('memberLead: null', escape: false);

    expect(Lead::count())->toBe(0);
});

// ---- spending ----

it('debits one credit for a single try-on and ties the spend to the generation', function () {
    Queue::fake();
    Http::fake();
    [$user, $lead, $token] = memberWithLead(credits: 4);

    $this->postJson('/api/fitting-room/generate', memberPayload($lead, $token))->assertStatus(202);

    $spend = CreditTransaction::where('reason', 'spend')->sole();
    expect(app(CreditService::class)->balance($user))->toBe(3)
        ->and($spend->generation_id)->toBe(Generation::sole()->id);
});

it('debits one credit per garment for an outfit', function () {
    Queue::fake();
    Http::fake();
    [$user, $lead, $token] = memberWithLead(credits: 4);

    $upper = Product::factory()->create(['category' => 'upper_body']);
    $lower = Product::factory()->create(['category' => 'lower_body']);

    $this->postJson('/api/fitting-room/generate-outfit', [
        'lead_id' => $lead->id,
        'lead_token' => $token,
        'product_ids' => [$upper->id, $lower->id],
        'photo_key' => 'uploads/2026-06-12/abc.jpg',
        'consent_id' => PhotoConsent::factory()->create()->id,
    ])->assertStatus(202);

    expect(app(CreditService::class)->balance($user))->toBe(2);
});

it('answers 402 credits_exhausted on an empty ledger — nothing spent, cap untouched', function () {
    Queue::fake();
    Http::fake();
    [, $lead, $token] = memberWithLead(credits: 0);

    $this->postJson('/api/fitting-room/generate', memberPayload($lead, $token))
        ->assertStatus(402)
        ->assertJsonPath('code', 'credits_exhausted');

    expect(Generation::count())->toBe(0)
        ->and(CreditTransaction::where('reason', 'spend')->count())->toBe(0)
        // The unwound global-cap claim left the counter as it found it.
        ->and((int) Cache::get('generation:global', 0))->toBe(0);
});

it('is not throttled by the guest daily flood guard — credits are the member budget', function () {
    config(['fitting_room.generation.per_user_daily_limit' => 3]);
    Queue::fake();
    Http::fake();
    [$user, $lead, $token] = memberWithLead(credits: 5);

    // A 4th generation would 429 for a guest; a member with credits sails on.
    foreach (range(1, 4) as $i) {
        $this->postJson('/api/fitting-room/generate', memberPayload($lead, $token))->assertStatus(202);
    }

    expect(app(CreditService::class)->balance($user))->toBe(1);
});

// ---- the ownership token boundary ----

it('rejects a member lead without its token — enumeration burns nothing', function () {
    Queue::fake();
    Http::fake();
    [$user, $lead] = memberWithLead(credits: 4);

    $payload = memberPayload($lead, 'not-the-token');
    $this->postJson('/api/fitting-room/generate', $payload)->assertStatus(403);

    unset($payload['lead_token']);
    $this->postJson('/api/fitting-room/generate', $payload)->assertStatus(403);

    Http::assertNothingSent();
    expect(Generation::count())->toBe(0)
        ->and(app(CreditService::class)->balance($user))->toBe(4);
});

it('rejects the outfit endpoint equally without the token', function () {
    Queue::fake();
    Http::fake();
    [, $lead] = memberWithLead(credits: 4);

    $upper = Product::factory()->create(['category' => 'upper_body']);
    $lower = Product::factory()->create(['category' => 'lower_body']);

    $this->postJson('/api/fitting-room/generate-outfit', [
        'lead_id' => $lead->id,
        'product_ids' => [$upper->id, $lower->id],
        'photo_key' => 'uploads/2026-06-12/abc.jpg',
        'consent_id' => PhotoConsent::factory()->create()->id,
    ])->assertStatus(403);

    expect(Generation::count())->toBe(0);
});

it('still accepts a plain GUEST lead without any token (flag on)', function () {
    Queue::fake();
    Http::fake();

    $this->postJson('/api/fitting-room/generate', [
        'lead_id' => Lead::factory()->create()->id,
        'product_id' => Product::factory()->create()->id,
        'photo_key' => 'uploads/2026-06-12/abc.jpg',
        'consent_id' => PhotoConsent::factory()->create()->id,
    ])->assertStatus(202);
});

// ---- refunds on failure ----

it('refunds the credit when the generation job fails', function () {
    config(['services.fashn.key' => 'test-key']);
    Storage::disk('r2')->buildTemporaryUrlsUsing(fn ($path, $exp, $opts = []) => 'https://r2.test/'.$path);
    Sleep::fake();
    // The engine accepts the run, then reports the prediction failed.
    Http::fake([
        '*/run' => Http::response(['id' => 'pred_fail', 'error' => null], 200),
        '*/status/*' => Http::response(['id' => 'pred_fail', 'status' => 'failed', 'error' => 'model error'], 200),
    ]);

    [$user, $lead, $token] = memberWithLead(credits: 4);

    // No Queue::fake — the job runs inline and hits the failure path for real.
    $this->postJson('/api/fitting-room/generate', memberPayload($lead, $token))->assertStatus(202);

    expect(Generation::sole()->status)->toBe('failed')
        ->and(CreditTransaction::where('reason', 'refund')->count())->toBe(1)
        ->and(app(CreditService::class)->balance($user))->toBe(4); // made whole
});

it('refunds the whole outfit spend when the chain fails', function () {
    config(['services.fashn.key' => 'test-key']);
    Storage::disk('r2')->buildTemporaryUrlsUsing(fn ($path, $exp, $opts = []) => 'https://r2.test/'.$path);
    Sleep::fake();
    Queue::fake(); // the endpoint books the spend; the chain runs below
    Http::fake([
        '*/run' => Http::response(['id' => 'pred_chain', 'error' => null], 200),
        '*/status/*' => Http::response(['id' => 'pred_chain', 'status' => 'failed', 'error' => 'model error'], 200),
    ]);

    [$user, $lead, $token] = memberWithLead(credits: 4);

    $upper = Product::factory()->create(['category' => 'upper_body']);
    $lower = Product::factory()->create(['category' => 'lower_body']);

    $this->postJson('/api/fitting-room/generate-outfit', [
        'lead_id' => $lead->id,
        'lead_token' => $token,
        'product_ids' => [$upper->id, $lower->id],
        'photo_key' => 'uploads/2026-06-12/abc.jpg',
        'consent_id' => PhotoConsent::factory()->create()->id,
    ])->assertStatus(202);

    expect(app(CreditService::class)->balance($user))->toBe(2); // both units booked

    // Run the queued chain for real (invoked directly — dispatchSync would be
    // swallowed by Queue::fake) — step 1 fails → the whole chain fails → the
    // FULL spend comes back.
    app()->call([new GenerateOutfitJob(Generation::sole()), 'handle']);

    expect(Generation::sole()->status)->toBe('failed')
        ->and(app(CreditService::class)->balance($user))->toBe(4);
});
