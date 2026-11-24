<?php

use App\Models\Lead;
use App\Models\PhotoConsent;
use App\Models\Product;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * The consent gate. The privacy documents promise that nothing is processed until
 * the visitor explicitly agrees — so the SERVER has to enforce it, not the
 * checkbox. These tests bypass the browser entirely, which is exactly the case a
 * front-end-only gate would miss.
 */

beforeEach(function () {
    Storage::fake('r2');
    Queue::fake();
});

function photoFile(): UploadedFile
{
    return UploadedFile::fake()->image('me.jpg', 800, 1200);
}

/** The upload payload with consent, as the interface sends it. */
function consentedUpload(array $overrides = []): array
{
    return array_merge([
        'photo' => photoFile(),
        'consent_accepted' => '1',
        'consent_version' => (string) config('consent.version'),
        'terms_accepted' => '1',
        'terms_version' => (string) config('terms.version'),
    ], $overrides);
}

it('refuses an upload with no consent at all, and stores nothing', function () {
    $this->postJson('/api/fitting-room/photo', ['photo' => photoFile()])
        ->assertStatus(422)
        ->assertJsonValidationErrors('consent_accepted');

    expect(PhotoConsent::count())->toBe(0)
        ->and(Storage::disk('r2')->allFiles())->toBeEmpty();
});

it('refuses an upload where the box was left unticked', function () {
    // An unticked checkbox posts nothing at all, but a hand-built request can send
    // an explicit false — "accepted" refuses both.
    $this->postJson('/api/fitting-room/photo', consentedUpload(['consent_accepted' => false]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('consent_accepted');

    expect(Storage::disk('r2')->allFiles())->toBeEmpty();
});

it('refuses consent given against a superseded wording', function () {
    $this->postJson('/api/fitting-room/photo', consentedUpload(['consent_version' => 'legacy-0']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('consent_version');

    expect(Storage::disk('r2')->allFiles())->toBeEmpty();
});

it('records the consent with its version and timestamp, and returns its id', function () {
    $response = $this->postJson('/api/fitting-room/photo', consentedUpload())
        ->assertStatus(201)
        ->assertJsonStructure(['photo_key', 'consent_id', 'expires_at']);

    $consent = PhotoConsent::sole();

    expect($consent->id)->toBe($response->json('consent_id'))
        ->and($consent->consent_version)->toBe((string) config('consent.version'))
        ->and($consent->accepted_at)->not->toBeNull()
        ->and($consent->isCurrent())->toBeTrue();
});

it('keeps the consent record even though the photo it authorised expires in 72 hours', function () {
    // The record is the proof; it deliberately outlives the photo. Its own
    // retention is the (longer) consent period in config/privacy.php.
    $this->postJson('/api/fitting-room/photo', consentedUpload())->assertStatus(201);

    expect(PhotoConsent::count())->toBe(1)
        ->and((int) config('privacy.retention.consent_years'))->toBeGreaterThan(0);
});

it('refuses to start a generation without a consent id', function () {
    $lead = Lead::factory()->create();
    $product = Product::factory()->verified()->create();

    $this->postJson('/api/fitting-room/generate', [
        'lead_id' => $lead->id,
        'product_id' => $product->id,
        'photo_key' => 'uploads/2026-08-25/x.jpg',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('consent_id');

    Queue::assertNothingPushed();
});

it('refuses to start a generation whose consent was given for older wording', function () {
    $lead = Lead::factory()->create();
    $product = Product::factory()->verified()->create();
    $stale = PhotoConsent::factory()->outdated()->create();

    $this->postJson('/api/fitting-room/generate', [
        'lead_id' => $lead->id,
        'product_id' => $product->id,
        'photo_key' => 'uploads/2026-08-25/x.jpg',
        'consent_id' => $stale->id,
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('consent_id');

    Queue::assertNothingPushed();
});

it('refuses to start an outfit generation without a consent id', function () {
    $lead = Lead::factory()->create();
    $lower = Product::factory()->verified()->lowerBody()->create();
    $upper = Product::factory()->verified()->upperBody()->create();

    $this->postJson('/api/fitting-room/generate-outfit', [
        'lead_id' => $lead->id,
        'product_ids' => [$lower->id, $upper->id],
        'photo_key' => 'uploads/2026-08-25/x.jpg',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('consent_id');

    Queue::assertNothingPushed();
});

it('shows an unticked consent checkbox before the upload zone, in the public interface language', function () {
    $response = $this->get('/ai-fitting-room')->assertOk();

    $html = $response->getContent();

    // Present, and NOT pre-filled: no checked attribute anywhere on it.
    expect($html)->toContain('id="photo-consent"')
        ->and($html)->toContain(config('consent.text'));

    $checkbox = Str::of($html)->after('id="photo-consent"')->before('>')->toString();
    expect($checkbox)->not->toContain('checked');

    // The gate sits ABOVE the upload zone, not after it.
    expect(strpos($html, 'id="photo-consent"'))->toBeLessThan(strpos($html, 'id="photo-dropzone"'));
});

/*
 * --- accepting the Terms (clickwrap) ---
 *
 * "By using the service you agree" is easy to write and impossible to prove.
 * The Terms are accepted by ticking a box before the first upload, recorded with
 * their version, and the server refuses an upload without it — separately from
 * the photo consent, so neither makes the other unfree.
 */

it('refuses an upload when the Terms have not been accepted', function () {
    $this->postJson('/api/fitting-room/photo', consentedUpload(['terms_accepted' => false]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('terms_accepted');

    expect(Storage::disk('r2')->allFiles())->toBeEmpty()
        ->and(PhotoConsent::count())->toBe(0);
});

it('refuses acceptance of a superseded version of the Terms', function () {
    $this->postJson('/api/fitting-room/photo', consentedUpload(['terms_version' => 'legacy-0']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('terms_version');
});

it('records the accepted Terms version and time alongside the photo consent', function () {
    $this->postJson('/api/fitting-room/photo', consentedUpload())->assertStatus(201);

    $consent = PhotoConsent::sole();

    expect($consent->terms_version)->toBe((string) config('terms.version'))
        ->and($consent->terms_accepted_at)->not->toBeNull()
        // Same moment, one row: the record of the consent cannot survive without
        // the record of the acceptance that came with it.
        ->and($consent->accepted_at->timestamp)->toBe($consent->terms_accepted_at->timestamp);
});

it('refuses to generate from a consent whose Terms acceptance is missing', function () {
    $lead = Lead::factory()->create();
    $product = Product::factory()->verified()->create();
    $noTerms = PhotoConsent::factory()->create(['terms_version' => null, 'terms_accepted_at' => null]);

    $this->postJson('/api/fitting-room/generate', [
        'lead_id' => $lead->id,
        'product_id' => $product->id,
        'photo_key' => 'uploads/2026-08-25/x.jpg',
        'consent_id' => $noTerms->id,
    ])->assertStatus(422)->assertJsonValidationErrors('consent_id');
});

it('offers the Terms box unticked and separate from the photo consent', function () {
    $html = $this->get('/ai-fitting-room')->assertOk()->getContent();

    expect($html)->toContain('id="terms-accept"')
        ->and($html)->toContain(config('terms.text'));

    // Two distinct inputs, neither pre-filled.
    $terms = Str::of($html)->after('id="terms-accept"')->before('>')->toString();
    expect($terms)->not->toContain('checked')
        ->and($html)->toContain('id="photo-consent"');
});
