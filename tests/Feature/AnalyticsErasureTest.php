<?php

use App\Models\Lead;
use App\Services\Analytics\AnalyticsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/*
 * Этап A4 — erasure reaches the analytics too: app:delete-user-data removes the
 * pseudonymous person from PostHog by distinct id. Idempotent, and NEVER able
 * to block the rest of an erasure — a PostHog failure only warns.
 */

beforeEach(function () {
    Storage::fake('r2');
    config([
        'analytics.enabled' => true,
        'analytics.posthog.key' => 'phc_test',
        'analytics.posthog.personal_key' => 'phx_personal',
        'analytics.posthog.project_id' => '777',
    ]);
});

it('deletes the PostHog person for each erased lead, events included', function () {
    Http::fake([
        'https://eu.i.posthog.com/api/projects/777/persons/*' => Http::sequence()
            ->push(['results' => [['id' => 'person-uuid-1']]], 200)  // lookup
            ->push([], 204),                                         // delete
    ]);

    $lead = Lead::factory()->create();
    $hash = app(AnalyticsService::class)->leadDistinctId($lead->id);

    $this->artisan('app:delete-user-data', ['--lead' => (string) $lead->id, '--force' => true])
        ->expectsOutputToContain('pseudonymous person(s) deleted from PostHog')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => str_contains($request->url(), '/api/projects/777/persons/')
        && $request->method() === 'GET'
        && str_contains($request->url(), 'distinct_id='.$hash));

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && str_contains($request->url(), '/persons/person-uuid-1/'));
});

it('treats an unknown distinct id as already erased (idempotent)', function () {
    Http::fake([
        'https://eu.i.posthog.com/api/projects/777/persons/*' => Http::response(['results' => []], 200),
    ]);

    $lead = Lead::factory()->create();

    $this->artisan('app:delete-user-data', ['--lead' => (string) $lead->id, '--force' => true])
        ->expectsOutputToContain('pseudonymous person(s) deleted from PostHog')
        ->assertSuccessful();
});

it('still completes the erasure when PostHog is down — with a warning, not a failure', function () {
    Http::fake([
        'https://eu.i.posthog.com/*' => Http::response('down', 503),
    ]);

    $lead = Lead::factory()->create();

    $this->artisan('app:delete-user-data', ['--lead' => (string) $lead->id, '--force' => true])
        ->expectsOutputToContain('could not be confirmed deleted in PostHog')
        ->assertSuccessful();

    expect(Lead::query()->find($lead->id))->toBeNull();
});

it('skips the PostHog step entirely while analytics is disabled', function () {
    config(['analytics.enabled' => false]);
    Http::fake();

    $lead = Lead::factory()->create();

    $this->artisan('app:delete-user-data', ['--lead' => (string) $lead->id, '--force' => true])
        ->assertSuccessful();

    Http::assertNothingSent();
});
