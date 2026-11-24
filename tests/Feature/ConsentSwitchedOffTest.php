<?php

use App\Jobs\AnalyzeColorProfile;
use App\Models\ColorProfile;
use App\Models\Lead;
use App\Models\PhotoConsent;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * Consent switched off (config/consent.php, the shipped default): no boxes on
 * any surface, no gate on any endpoint — and no consent records either, so
 * nothing claims an agreement that was never asked for. The suite runs with
 * consent ON (phpunit.xml); this file is the off state.
 */

beforeEach(function () {
    config(['consent.required' => false]);
    Storage::fake('r2');
    Queue::fake();
});

it('ships switched off', function () {
    expect(file_get_contents(base_path('config/consent.php')))->toContain("env('CONSENT_REQUIRED', false)")
        ->and(file_get_contents(base_path('.env.example')))->toContain('CONSENT_REQUIRED=false');
});

it('takes a try-on photo without any consent fields and records no consent', function () {
    $this->postJson('/api/fitting-room/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 800, 1200)])
        ->assertCreated()
        ->assertJsonPath('consent_id', null);

    expect(PhotoConsent::count())->toBe(0)
        ->and(Storage::disk('r2')->allFiles())->toHaveCount(1);
});

it('still checks the photo itself', function () {
    $this->postJson('/api/fitting-room/photo', ['photo' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain')])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('photo');
});

it('starts a try-on without a consent record', function () {
    Http::fake();

    $this->postJson('/api/fitting-room/generate', [
        'lead_id' => Lead::factory()->create()->id,
        'product_id' => Product::factory()->create()->id,
        'photo_key' => 'uploads/2026-10-13/abc.jpg',
    ])->assertStatus(202);
});

it('starts an outfit without a consent record', function () {
    Http::fake();

    $this->postJson('/api/fitting-room/generate-outfit', [
        'lead_id' => Lead::factory()->create()->id,
        'product_ids' => [
            Product::factory()->create(['category' => 'upper_body'])->id,
            Product::factory()->create(['category' => 'lower_body'])->id,
        ],
        'photo_key' => 'uploads/2026-10-13/abc.jpg',
    ])->assertStatus(202);
});

it('takes a colour photo without the tick, and stamps no consent', function () {
    config(['features.membership' => true, 'features.color_analysis' => true]);

    $this->actingAs(User::factory()->emailOnly()->create())
        ->postJson('/account/color', ['photo' => UploadedFile::fake()->image('selfie.jpg', 900, 1200)])
        ->assertCreated();

    expect(ColorProfile::sole()->consented_at)->toBeNull();
    Queue::assertPushed(AnalyzeColorProfile::class);
});

it('takes the onboarding selfie without the tick, and stamps no consent', function () {
    config(['features.membership' => true, 'features.color_analysis' => true]);

    $this->actingAs(User::factory()->emailOnly()->create())
        ->post('/account/welcome', ['natural_hair' => '1', 'photo' => UploadedFile::fake()->image('selfie.jpg', 900, 1200)])
        ->assertRedirect(route('membership.account.color'));

    expect(ColorProfile::sole()->consented_at)->toBeNull();
});

it('shows no consent box on any surface', function () {
    config(['features.membership' => true, 'features.color_analysis' => true]);
    $member = User::factory()->emailOnly()->onboarded()->create();

    $this->actingAs($member)->get('/account')
        ->assertOk()
        ->assertDontSee('id="tryon-consent"', false)
        ->assertDontSee('id="m-tryon-consent"', false)
        ->assertDontSee('id="photo-consent"', false)
        ->assertSee('consent: true', false)
        ->assertSee('terms: true', false);

    $this->actingAs($member)->get('/account/color')
        ->assertOk()
        ->assertDontSee('id="color-consent"', false);

    $this->actingAs($member)->get('/account/welcome')
        ->assertOk()
        ->assertDontSee('id="selfie-consent"', false);

    $this->get('/ai-fitting-room')
        ->assertOk()
        ->assertDontSee('id="photo-consent"', false)
        ->assertDontSee('id="terms-accept"', false)
        ->assertSee('consentAccepted: true', false);
});
