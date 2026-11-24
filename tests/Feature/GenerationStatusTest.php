<?php

use App\Models\Generation;
use Illuminate\Support\Facades\URL;

/*
 * GET /api/fitting-room/generation/{id} — the signed status endpoint the loader
 * polls. Access is bound to a SIGNED URL (HMAC over the id), so nobody can
 * enumerate ids to read another person's generated images. No FASHN here.
 */

function signedStatusUrl(Generation $generation, ?int $expiresMinutes = 60): string
{
    return URL::temporarySignedRoute(
        'fitting-room.generation.status',
        now()->addMinutes($expiresMinutes),
        ['generation' => $generation->id],
    );
}

it('returns the status for a queued generation', function () {
    $generation = Generation::factory()->create(['status' => 'queued']);

    $this->getJson(signedStatusUrl($generation))
        ->assertOk()
        ->assertJsonPath('status', 'queued');
});

it('returns the status for a processing generation', function () {
    $generation = Generation::factory()->processing()->create();

    $this->getJson(signedStatusUrl($generation))
        ->assertOk()
        ->assertJsonPath('status', 'processing');
});

it('returns result_urls when the generation is done', function () {
    $generation = Generation::factory()->create([
        'status' => 'done',
        'result_urls' => [
            ['url' => 'https://cdn.fashn.test/look1.png', 'with_background' => false],
            ['url' => 'https://cdn.fashn.test/look2.png', 'with_background' => false],
        ],
    ]);

    $this->getJson(signedStatusUrl($generation))
        ->assertOk()
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('result_urls.0.url', 'https://cdn.fashn.test/look1.png')
        ->assertJsonCount(2, 'result_urls');
});

it('returns a safe, generic message when the generation failed (no internal error echoed)', function () {
    // The stored error is an internal diagnostic — it must NOT reach the visitor.
    $generation = Generation::factory()->create([
        'status' => 'failed',
        'error' => 'ContentModerationError: internal-diagnostic-xyz',
    ]);

    $response = $this->getJson(signedStatusUrl($generation))
        ->assertOk()
        ->assertJsonPath('status', 'failed');

    expect($response->json('message'))->toBeString()->not->toBe('');
    // The internal error string never appears in the response.
    expect($response->getContent())->not->toContain('internal-diagnostic-xyz');
});

it('does not expose result_urls while still processing', function () {
    $generation = Generation::factory()->processing()->create();

    $this->getJson(signedStatusUrl($generation))
        ->assertOk()
        ->assertJsonMissingPath('result_urls');
});

it('never leaks internal fields (client photo key, prediction id, lead/ip)', function () {
    $generation = Generation::factory()->create([
        'status' => 'done',
        'client_photo' => 'uploads/2026-06-12/super-secret-key.jpg',
        'fashn_prediction_id' => 'pred_secret_999',
        'result_urls' => [['url' => 'https://cdn.fashn.test/look.png', 'with_background' => false]],
    ]);

    $response = $this->getJson(signedStatusUrl($generation))->assertOk();

    // Neither the keys nor their values are present anywhere in the payload.
    $response->assertJsonMissingPath('client_photo')
        ->assertJsonMissingPath('fashn_prediction_id')
        ->assertJsonMissingPath('lead_id')
        ->assertJsonMissingPath('ip');

    expect($response->getContent())
        ->not->toContain('super-secret-key')
        ->and($response->getContent())->not->toContain('pred_secret_999');
});

it('rejects access without a valid signature (no id enumeration)', function () {
    $generation = Generation::factory()->create();

    // A bare GET by id, with no signature, is refused.
    $this->getJson('/api/fitting-room/generation/'.$generation->id)
        ->assertStatus(403);
});

it('rejects a signature minted for a DIFFERENT generation (cannot read someone else\'s)', function () {
    $mine = Generation::factory()->create();
    $someoneElse = Generation::factory()->create();

    // Take my valid signed URL and point it at another generation's id: the HMAC
    // covers the id, so tampering invalidates it.
    $tampered = str_replace(
        '/generation/'.$mine->id,
        '/generation/'.$someoneElse->id,
        signedStatusUrl($mine),
    );

    $this->getJson($tampered)->assertStatus(403);
});

it('refuses a restore whose signature has been tampered with', function () {
    // The refresh-restore flow rebuilds this signed URL from the address bar; a
    // forged signature must not open the generation (403, no leak).
    $generation = Generation::factory()->create([
        'status' => 'done',
        'result_urls' => [['url' => 'https://cdn.fashn.test/look.png', 'with_background' => false]],
    ]);

    $tampered = preg_replace_callback('/signature=([0-9a-f]+)/', function ($m) {
        $sig = $m[1];
        $flip = substr($sig, -1) === 'a' ? 'b' : 'a';

        return 'signature='.substr($sig, 0, -1).$flip;
    }, signedStatusUrl($generation));

    $this->getJson($tampered)->assertStatus(403);
});

it('restores a done generation through a valid signed URL (the refresh happy path)', function () {
    $generation = Generation::factory()->create([
        'status' => 'done',
        'result_urls' => [['url' => 'https://cdn.fashn.test/look.png', 'with_background' => false]],
    ]);

    // The page rebuilds exactly this signed status URL on load.
    $this->getJson(signedStatusUrl($generation))
        ->assertOk()
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('result_urls.0.url', 'https://cdn.fashn.test/look.png');
});

it('omits the background loader step when with_background is false (honest loader)', function () {
    $generation = Generation::factory()->create(['with_background' => false, 'status' => 'processing']);

    $response = $this->getJson(signedStatusUrl($generation))->assertOk();

    $steps = $response->json('loader_steps');
    // Config names the steps by translation key; the endpoint sends the words.
    expect($steps)->toContain(__(config('fitting_room.loader.final_step')))
        ->and($steps)->toContain('Створюємо результат')
        ->and($steps)->not->toContain(__(config('fitting_room.loader.background_step')));
});

it('includes the background loader step only when with_background is true', function () {
    $generation = Generation::factory()->withBackground()->create(['status' => 'processing']);

    $response = $this->getJson(signedStatusUrl($generation))->assertOk();

    expect($response->json('loader_steps'))
        ->toContain(__(config('fitting_room.loader.background_step')));
});
