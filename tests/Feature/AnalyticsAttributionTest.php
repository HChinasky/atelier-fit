<?php

use App\Http\Middleware\TrackAttribution;
use App\Jobs\SendAnalyticsEventJob;
use App\Models\Lead;
use Illuminate\Support\Facades\Queue;

/*
 * Этап A1 — UTM attribution: campaign parameters fold into the session
 * (first_touch write-once, last_touch overwritten), land on the lead row at
 * capture, and stay strictly internal (never in a response body).
 */

it('stores first and last touch from utm parameters on a public page view', function () {
    $this->get('/ai-fitting-room?utm_source=instagram&utm_medium=social&utm_campaign=launch&ref=blogger1')
        ->assertOk();

    $first = session(TrackAttribution::SESSION_FIRST);

    expect($first['utm_source'])->toBe('instagram')
        ->and($first['utm_medium'])->toBe('social')
        ->and($first['utm_campaign'])->toBe('launch')
        ->and($first['ref'])->toBe('blogger1')
        ->and($first['landing_path'])->toBe('/ai-fitting-room')
        ->and(session(TrackAttribution::SESSION_LAST)['utm_source'])->toBe('instagram');
});

it('never overwrites first touch but always refreshes last touch', function () {
    $this->get('/ai-fitting-room?utm_source=instagram&utm_campaign=launch')->assertOk();
    $this->get('/ai-fitting-room?utm_source=whatsapp&utm_campaign=retarget')->assertOk();

    expect(session(TrackAttribution::SESSION_FIRST)['utm_source'])->toBe('instagram')
        ->and(session(TrackAttribution::SESSION_LAST)['utm_source'])->toBe('whatsapp')
        ->and(session(TrackAttribution::SESSION_LAST)['utm_campaign'])->toBe('retarget');
});

it('records the referrer host only — never its path or query', function () {
    $this->get('/ai-fitting-room', ['referer' => 'https://l.instagram.com/some/path?u=secret'])
        ->assertOk();

    expect(session(TrackAttribution::SESSION_FIRST)['referrer_host'])->toBe('l.instagram.com');
});

it('writes first-touch attribution onto the lead created in the same session', function () {
    $this->get('/ai-fitting-room?utm_source=instagram&utm_medium=social&utm_campaign=launch')->assertOk();

    $response = $this->postJson('/api/lead', [
        'channel' => 'whatsapp',
        'contact' => '+971501234567',
    ])->assertCreated();

    $lead = Lead::findOrFail($response->json('lead_id'));

    expect($lead->utm_source)->toBe('instagram')
        ->and($lead->utm_medium)->toBe('social')
        ->and($lead->utm_campaign)->toBe('launch')
        ->and($lead->landing_path)->toBe('/ai-fitting-room');

    // Internal-only: nothing about attribution appears in the response.
    expect(json_encode($response->json()))->not->toContain('utm');
});

it('leaves attribution columns null for a lead with no campaign context', function () {
    $response = $this->postJson('/api/lead', [
        'channel' => 'email',
        'contact' => 'guest@example.com',
    ])->assertCreated();

    $lead = Lead::findOrFail($response->json('lead_id'));

    expect($lead->utm_source)->toBeNull()
        ->and($lead->referrer_host)->toBeNull();
});

it('truncates oversized campaign values instead of storing attacker-length input', function () {
    $this->get('/ai-fitting-room?utm_source='.str_repeat('a', 900))->assertOk();

    expect(mb_strlen(session(TrackAttribution::SESSION_FIRST)['utm_source']))->toBe(255);
});

it('fires landing_viewed once per session with the campaign context', function () {
    config(['analytics.enabled' => true, 'analytics.posthog.key' => 'phc_test']);
    Queue::fake();

    $this->get('/ai-fitting-room?utm_source=instagram')->assertOk();
    $this->get('/ai-fitting-room')->assertOk();

    Queue::assertPushed(SendAnalyticsEventJob::class, 1);
    Queue::assertPushed(SendAnalyticsEventJob::class, function (SendAnalyticsEventJob $job) {
        return $job->payload['event'] === 'landing_viewed'
            && $job->payload['properties']['utm_source'] === 'instagram'
            && $job->payload['properties']['landing_path'] === '/ai-fitting-room';
    });
});

it('keeps the health probe free of sessions and analytics', function () {
    config(['analytics.enabled' => true, 'analytics.posthog.key' => 'phc_test']);
    Queue::fake();

    $this->get('/health?utm_source=curl');

    Queue::assertNothingPushed();
});
