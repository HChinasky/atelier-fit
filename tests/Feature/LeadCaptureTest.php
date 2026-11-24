<?php

use App\Models\Lead;
use App\Services\Lead\LeadNotifier;
use Illuminate\Support\Facades\Http;

/*
 * POST /api/lead — captures a contact into our own database. There is NO external
 * funnel tool yet, so nothing leaves the system: the LeadNotifier default is a
 * no-op. These tests pin the validation, the persisted shape, the rate limit, and
 * the contract that external delivery is absent and cannot lose a lead.
 */

it('stores a valid email lead with the try-on-user tag', function () {
    $response = $this->postJson('/api/lead', [
        'channel' => 'email',
        'contact' => 'shopper@example.com',
    ]);

    $response->assertCreated()->assertJsonPath('status', 'ok');

    $lead = Lead::sole();
    expect($lead->channel)->toBe('email')
        ->and($lead->contact)->toBe('shopper@example.com')
        ->and($lead->tags)->toContain('try-on-user')
        ->and($lead->ip)->not->toBeNull()
        // No external delivery happened.
        ->and($lead->notified_at)->toBeNull();
});

it('stores a valid whatsapp lead with the try-on-user tag', function () {
    $response = $this->postJson('/api/lead', [
        'channel' => 'whatsapp',
        'contact' => '+971501234567',
    ]);

    $response->assertCreated();

    $lead = Lead::sole();
    expect($lead->channel)->toBe('whatsapp')
        ->and($lead->contact)->toBe('+971501234567')
        ->and($lead->tags)->toContain('try-on-user');
});

it('rejects an email contact that is not a valid email', function () {
    $this->postJson('/api/lead', ['channel' => 'email', 'contact' => 'not-an-email'])
        ->assertStatus(422);

    expect(Lead::count())->toBe(0);
});

it('rejects a whatsapp contact that is not a valid phone number', function () {
    $this->postJson('/api/lead', ['channel' => 'whatsapp', 'contact' => 'call-me-maybe'])
        ->assertStatus(422);

    expect(Lead::count())->toBe(0);
});

it('rejects an email address submitted under the whatsapp channel', function () {
    $this->postJson('/api/lead', ['channel' => 'whatsapp', 'contact' => 'shopper@example.com'])
        ->assertStatus(422);

    expect(Lead::count())->toBe(0);
});

it('rejects an unknown channel', function () {
    $this->postJson('/api/lead', ['channel' => 'telegram', 'contact' => 'shopper@example.com'])
        ->assertStatus(422);

    expect(Lead::count())->toBe(0);
});

it('rate limits the endpoint after the allowed number of requests', function () {
    $payload = ['channel' => 'whatsapp', 'contact' => '+971501234567'];

    for ($i = 0; $i < 5; $i++) {
        $this->postJson('/api/lead', $payload)->assertCreated();
    }

    // The 6th within the window is throttled.
    $this->postJson('/api/lead', $payload)->assertStatus(429);
});

it('makes no external call and leaves notified_at null (no notifier configured)', function () {
    // If any HTTP went out, this fake would record it.
    Http::fake();

    $this->postJson('/api/lead', ['channel' => 'email', 'contact' => 'shopper@example.com'])
        ->assertCreated();

    Http::assertNothingSent();
    expect(Lead::sole()->notified_at)->toBeNull();
});

it('invokes the lead notifier seam', function () {
    $notifier = Mockery::mock(LeadNotifier::class);
    $notifier->shouldReceive('notify')->once();
    $this->app->instance(LeadNotifier::class, $notifier);

    $this->postJson('/api/lead', ['channel' => 'email', 'contact' => 'shopper@example.com'])
        ->assertCreated();
});

it('does not lose the lead if external notification fails', function () {
    $notifier = Mockery::mock(LeadNotifier::class);
    $notifier->shouldReceive('notify')->andThrow(new RuntimeException('downstream down'));
    $this->app->instance(LeadNotifier::class, $notifier);

    $this->postJson('/api/lead', ['channel' => 'email', 'contact' => 'shopper@example.com'])
        ->assertCreated();

    // The lead is safely persisted despite the notifier blowing up.
    expect(Lead::count())->toBe(1);
});
