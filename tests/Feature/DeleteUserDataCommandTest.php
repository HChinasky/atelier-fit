<?php

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\Lead;
use App\Models\OtpCode;
use App\Models\Product;
use App\Models\User;
use App\Models\WelcomeGrant;
use Illuminate\Support\Facades\Storage;

/*
 * app:delete-user-data — the operator's tool for honouring an erasure request or a
 * withdrawal of consent, which the privacy policy grants as a right. Until the
 * account area offers a self-service button, this command IS that right.
 *
 * The invariant every test here protects: everything personal goes, and the
 * irreversible e-mail hash in welcome_grants stays.
 */

beforeEach(function () {
    Storage::fake('r2');
});

/** A lead with a stored upload, its processed photo and a stored result behind it. */
function leadWithData(array $attributes = []): Lead
{
    $lead = Lead::factory()->create($attributes);

    Storage::disk('r2')->put('uploads/2026-08-01/upload-'.$lead->id.'.jpg', 'UPLOAD');
    Storage::disk('r2')->put('uploads/2026-08-01/photo-'.$lead->id.'.jpg', 'PHOTO');
    Storage::disk('r2')->put('uploads/2026-08-01/look-'.$lead->id.'.png', 'RESULT');

    Generation::factory()->create([
        'lead_id' => $lead->id,
        'product_id' => Product::factory()->create()->id,
        'client_photo' => 'uploads/2026-08-01/photo-'.$lead->id.'.jpg',
        'source_photo' => 'uploads/2026-08-01/upload-'.$lead->id.'.jpg',
        'result_keys' => ['uploads/2026-08-01/look-'.$lead->id.'.png'],
        'status' => 'done',
    ]);

    return $lead;
}

it('erases an account and everything behind it when identified by e-mail', function () {
    $user = User::factory()->create(['email' => 'member@example.test']);
    $lead = leadWithData(['user_id' => $user->id]);
    OtpCode::factory()->create(['email' => 'member@example.test']);
    CreditTransaction::factory()->create(['user_id' => $user->id]);

    $this->artisan('app:delete-user-data', ['--email' => 'Member@Example.test', '--force' => true])
        ->assertSuccessful();

    expect(User::find($user->id))->toBeNull()
        ->and(Lead::find($lead->id))->toBeNull()
        ->and(Generation::where('lead_id', $lead->id)->count())->toBe(0)
        ->and(OtpCode::where('email', 'member@example.test')->count())->toBe(0)
        ->and(CreditTransaction::where('user_id', $user->id)->count())->toBe(0);

    // The images are actually gone, not merely left to expire — the kept
    // upload a look was processed from included.
    Storage::disk('r2')->assertMissing('uploads/2026-08-01/upload-'.$lead->id.'.jpg');
    Storage::disk('r2')->assertMissing('uploads/2026-08-01/photo-'.$lead->id.'.jpg');
    Storage::disk('r2')->assertMissing('uploads/2026-08-01/look-'.$lead->id.'.png');
});

it('erases a guest contact identified by email, with its photo and result', function () {
    $lead = leadWithData(['channel' => 'email', 'contact' => 'someone@example.test', 'user_id' => null]);

    $this->artisan('app:delete-user-data', ['--email' => 'someone@example.test', '--force' => true])
        ->assertSuccessful();

    expect(Lead::find($lead->id))->toBeNull();
    Storage::disk('r2')->assertMissing('uploads/2026-08-01/photo-'.$lead->id.'.jpg');
    Storage::disk('r2')->assertMissing('uploads/2026-08-01/look-'.$lead->id.'.png');
});

it('erases a single contact identified by lead id', function () {
    $lead = leadWithData();

    $this->artisan('app:delete-user-data', ['--lead' => (string) $lead->id, '--force' => true])
        ->assertSuccessful();

    expect(Lead::find($lead->id))->toBeNull();
});

it('KEEPS the permanent e-mail hash in welcome_grants when erasing an account', function () {
    $user = User::factory()->create(['email' => 'kept@example.test']);
    $grant = WelcomeGrant::factory()->create();

    $this->artisan('app:delete-user-data', ['--email' => 'kept@example.test', '--force' => true])
        ->assertSuccessful()
        // The operator is told, so a kept record can never look like a missed one.
        ->expectsOutputToContain('welcome_grants');

    expect(User::find($user->id))->toBeNull()
        ->and(WelcomeGrant::find($grant->id))->not->toBeNull();
});

it('touches nobody else\'s data', function () {
    $target = leadWithData(['channel' => 'email', 'contact' => 'target@example.test']);
    $bystander = leadWithData(['channel' => 'email', 'contact' => 'bystander@example.test']);

    $this->artisan('app:delete-user-data', ['--email' => 'target@example.test', '--force' => true])
        ->assertSuccessful();

    expect(Lead::find($target->id))->toBeNull()
        ->and(Lead::find($bystander->id))->not->toBeNull();

    Storage::disk('r2')->assertExists('uploads/2026-08-01/photo-'.$bystander->id.'.jpg');
});

it('erases nothing on a dry run', function () {
    $lead = leadWithData(['channel' => 'email', 'contact' => 'dry@example.test']);

    $this->artisan('app:delete-user-data', ['--email' => 'dry@example.test', '--dry-run' => true])
        ->assertSuccessful();

    expect(Lead::find($lead->id))->not->toBeNull();
    Storage::disk('r2')->assertExists('uploads/2026-08-01/photo-'.$lead->id.'.jpg');
});

it('refuses to run without an identifier', function () {
    $this->artisan('app:delete-user-data')->assertFailed();
});

it('reports plainly when nothing matches', function () {
    $this->artisan('app:delete-user-data', ['--email' => 'nobody@example.test', '--force' => true])
        ->expectsOutputToContain('nothing to erase')
        ->assertSuccessful();
});
