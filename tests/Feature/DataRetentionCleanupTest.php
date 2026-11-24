<?php

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\Lead;
use App\Models\MarketingConsent;
use App\Models\OtpCode;
use App\Models\PhotoConsent;
use App\Models\Product;
use App\Models\User;
use App\Models\WelcomeGrant;
use App\Services\Credits\CreditService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Storage;

/*
 * app:cleanup-expired-data — enforces the retention periods the privacy policy
 * publishes. The periods are config-driven (a lawyer may change them), so the
 * tests drive the config rather than hardcoded month counts.
 *
 * The rule this suite guards hardest: welcome_grants is permanent and must survive
 * every sweep.
 */

beforeEach(function () {
    Storage::fake('r2');

    config([
        'privacy.retention.lead_months' => 12,
        'privacy.retention.account_inactivity_months' => 24,
        'privacy.retention.log_days' => 90,
        'privacy.retention.consent_years' => 3,
    ]);
});

/** A lead with a generation behind it, aged to a given last-contact time. */
function agedLead(?Carbon\Carbon $lastContact = null, array $attributes = []): Lead
{
    $lead = Lead::factory()->create($attributes);

    Storage::disk('r2')->put('uploads/2026-01-01/photo-'.$lead->id.'.jpg', 'PHOTO');
    Storage::disk('r2')->put('uploads/2026-01-01/result-'.$lead->id.'.png', 'RESULT');

    Generation::factory()->create([
        'lead_id' => $lead->id,
        'product_id' => Product::factory()->create()->id,
        'client_photo' => 'uploads/2026-01-01/photo-'.$lead->id.'.jpg',
        'result_keys' => ['uploads/2026-01-01/result-'.$lead->id.'.png'],
        'status' => 'done',
    ]);

    if ($lastContact !== null) {
        // updated_at is the "last contact" signal, so it has to be set past the
        // model's own touch.
        $lead->forceFill(['updated_at' => $lastContact])->saveQuietly();
    }

    return $lead;
}

it('deletes leads past the retention period, with their generations and stored images', function () {
    $expired = agedLead(now()->subMonths(13));

    $this->artisan('app:cleanup-expired-data')->assertSuccessful();

    expect(Lead::find($expired->id))->toBeNull()
        ->and(Generation::where('lead_id', $expired->id)->count())->toBe(0);

    // The private objects went too — not left for the lifecycle rule to find.
    Storage::disk('r2')->assertMissing('uploads/2026-01-01/photo-'.$expired->id.'.jpg');
    Storage::disk('r2')->assertMissing('uploads/2026-01-01/result-'.$expired->id.'.png');
});

it('keeps leads that are still inside the retention period', function () {
    $fresh = agedLead(now()->subMonths(11));

    $this->artisan('app:cleanup-expired-data')->assertSuccessful();

    expect(Lead::find($fresh->id))->not->toBeNull()
        ->and(Generation::where('lead_id', $fresh->id)->count())->toBe(1);

    Storage::disk('r2')->assertExists('uploads/2026-01-01/photo-'.$fresh->id.'.jpg');
});

it('honours a changed retention period from config without a code change', function () {
    $lead = agedLead(now()->subMonths(7));

    // Untouched at 12 months...
    $this->artisan('app:cleanup-expired-data')->assertSuccessful();
    expect(Lead::find($lead->id))->not->toBeNull();

    // ...and gone once the lawyer-set period is shortened to 6.
    config(['privacy.retention.lead_months' => 6]);
    $this->artisan('app:cleanup-expired-data')->assertSuccessful();
    expect(Lead::find($lead->id))->toBeNull();
});

it('treats a retention period of 0 as "rule disabled" rather than "delete everything"', function () {
    $ancient = agedLead(now()->subYears(5));

    config(['privacy.retention.lead_months' => 0]);

    $this->artisan('app:cleanup-expired-data')->assertSuccessful();

    // A mistyped env must keep data, never destroy it.
    expect(Lead::find($ancient->id))->not->toBeNull();
});

it('deletes accounts after the inactivity window, with their leads, codes and credits', function () {
    $user = User::factory()->create(['email' => 'dormant@example.test']);
    $user->forceFill(['last_active_at' => now()->subMonths(25)])->saveQuietly();

    $lead = agedLead(null, ['user_id' => $user->id]);
    OtpCode::factory()->create(['email' => 'dormant@example.test']);
    CreditTransaction::factory()->create(['user_id' => $user->id]);

    $this->artisan('app:cleanup-expired-data')->assertSuccessful();

    expect(User::find($user->id))->toBeNull()
        ->and(Lead::find($lead->id))->toBeNull()
        ->and(OtpCode::where('email', 'dormant@example.test')->count())->toBe(0)
        ->and(CreditTransaction::where('user_id', $user->id)->count())->toBe(0);

    Storage::disk('r2')->assertMissing('uploads/2026-01-01/photo-'.$lead->id.'.jpg');
});

it('keeps accounts that signed in inside the inactivity window', function () {
    $user = User::factory()->create();
    $user->forceFill(['last_active_at' => now()->subMonths(23)])->saveQuietly();

    $this->artisan('app:cleanup-expired-data')->assertSuccessful();

    expect(User::find($user->id))->not->toBeNull();
});

it('NEVER deletes the permanent e-mail hash in welcome_grants', function () {
    $user = User::factory()->create();
    $user->forceFill(['last_active_at' => now()->subYears(9)])->saveQuietly();

    $grant = WelcomeGrant::factory()->create();

    $this->artisan('app:cleanup-expired-data')->assertSuccessful();

    // The account is gone; the anti-abuse hash deliberately outlives it, exactly as
    // the privacy policy discloses.
    expect(User::find($user->id))->toBeNull()
        ->and(WelcomeGrant::find($grant->id))->not->toBeNull()
        ->and(WelcomeGrant::count())->toBe(1);
});

it('deletes consent records past their (longer) retention period, keeping recent ones', function () {
    $old = PhotoConsent::factory()->expired()->create();
    $recent = PhotoConsent::factory()->create();

    $this->artisan('app:cleanup-expired-data')->assertSuccessful();

    expect(PhotoConsent::find($old->id))->toBeNull()
        ->and(PhotoConsent::find($recent->id))->not->toBeNull();
});

it('is idempotent — a second run finds nothing left to do', function () {
    agedLead(now()->subMonths(13));

    $this->artisan('app:cleanup-expired-data')->assertSuccessful();
    $this->artisan('app:cleanup-expired-data')->assertSuccessful();

    expect(Lead::count())->toBe(0);
});

it('deletes nothing on a dry run', function () {
    $lead = agedLead(now()->subMonths(13));
    $consent = PhotoConsent::factory()->expired()->create();

    $this->artisan('app:cleanup-expired-data', ['--dry-run' => true])->assertSuccessful();

    expect(Lead::find($lead->id))->not->toBeNull()
        ->and(PhotoConsent::find($consent->id))->not->toBeNull();

    Storage::disk('r2')->assertExists('uploads/2026-01-01/photo-'.$lead->id.'.jpg');
});

it('is registered on the daily schedule so retention actually runs unattended', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains($event->command ?? '', 'app:cleanup-expired-data'));

    expect($events)->not->toBeEmpty()
        ->and($events->first()->expression)->toBe('30 3 * * *');
});

/*
 * --- the two records that used to have no end date ---
 */

it('deletes a withdrawn marketing permission once it has aged, keeping active ones', function () {
    config(['privacy.retention.marketing_consent_years' => 3]);

    $withdrawnLongAgo = MarketingConsent::factory()->create([
        'unsubscribed_at' => now()->subYears(3)->subDay(),
    ]);
    $withdrawnRecently = MarketingConsent::factory()->create(['unsubscribed_at' => now()->subMonth()]);
    $stillActive = MarketingConsent::factory()->create();

    $this->artisan('app:cleanup-expired-data')->assertSuccessful();

    expect(MarketingConsent::find($withdrawnLongAgo->id))->toBeNull()
        ->and(MarketingConsent::find($withdrawnRecently->id))->not->toBeNull()
        // An active permission is what authorises the next message; deleting it
        // would erase the permission itself, not just the record of it.
        ->and(MarketingConsent::find($stillActive->id))->not->toBeNull();
});

it('keeps the phone hash while the account exists, and drops it long after the account is erased', function () {
    config(['privacy.retention.welcome_grant_months' => 24]);

    $liveAccountHash = WelcomeGrant::factory()->create();
    $recentlyErased = WelcomeGrant::factory()->create(['account_removed_at' => now()->subMonths(23)]);
    $longErased = WelcomeGrant::factory()->create(['account_removed_at' => now()->subMonths(25)]);

    $this->artisan('app:cleanup-expired-data')->assertSuccessful();

    expect(WelcomeGrant::find($liveAccountHash->id))->not->toBeNull()
        ->and(WelcomeGrant::find($recentlyErased->id))->not->toBeNull()
        ->and(WelcomeGrant::find($longErased->id))->toBeNull();
});

it('stamps the e-mail hash when the account is erased, instead of deleting it', function () {
    $user = User::factory()->create(['email' => 'erased@example.test']);
    $hash = app(CreditService::class)->identityHash('erased@example.test');
    $grant = WelcomeGrant::factory()->create(['identity_hash' => $hash]);

    $this->artisan('app:delete-user-data', ['--email' => 'erased@example.test', '--force' => true])
        ->assertSuccessful();

    // Deleting it here would hand the bonus back to anyone who erases and
    // re-registers — the reason the hash exists at all.
    $grant->refresh();
    expect(User::find($user->id))->toBeNull()
        ->and($grant)->not->toBeNull()
        ->and($grant->account_removed_at)->not->toBeNull();
});
