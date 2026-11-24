<?php

use App\Models\Lead;
use App\Services\Generation\GenerationGate;

/*
 * The global hard cap must be atomic: increment-and-check is one indivisible
 * step, so the counter can never let more than N requests through. True
 * parallelism isn't reproducible single-threaded, so we prove the property the
 * mechanism guarantees: across N+K attempts, EXACTLY N are allowed and the
 * counter never exceeds N — the atomic claim is what makes that hold under
 * concurrency too.
 */

it('allows exactly the global cap across many attempts and never exceeds it', function () {
    $cap = 3;
    config([
        'fitting_room.generation.global_cap' => $cap,
        // High per-user limit + a distinct IP per attempt, so ONLY the global
        // cap is under test (the per-user guard never interferes).
        'fitting_room.generation.per_user_daily_limit' => 1000,
    ]);

    $gate = app(GenerationGate::class);
    $lead = Lead::factory()->create();

    $allowed = 0;
    $rejected = 0;

    for ($i = 0; $i < $cap + 7; $i++) {
        if ($gate->attempt($lead, '10.0.0.'.$i)->allowed) {
            $allowed++;
        } else {
            $rejected++;
        }
    }

    expect($allowed)->toBe($cap)
        ->and($rejected)->toBe(7)
        // The atomic counter never overshot the cap.
        ->and((int) cache()->get('generation:global'))->toBeLessThanOrEqual($cap);
});

it('rejects with a 503 service-unavailable result once the cap is reached', function () {
    config(['fitting_room.generation.global_cap' => 1, 'fitting_room.generation.per_user_daily_limit' => 1000]);

    $gate = app(GenerationGate::class);
    $lead = Lead::factory()->create();

    expect($gate->attempt($lead, '10.0.0.1')->allowed)->toBeTrue();

    $rejection = $gate->attempt($lead, '10.0.0.2');
    expect($rejection->allowed)->toBeFalse()
        ->and($rejection->status)->toBe(503);
});

it('consumes real credits against the cap, not a flat one-per-generation', function () {
    config(['fitting_room.generation.global_cap' => 4, 'fitting_room.generation.per_user_daily_limit' => 1000]);

    $gate = app(GenerationGate::class);
    $lead = Lead::factory()->create();

    // A 2-look + background generation costs 4 → fills the cap in a single attempt.
    expect($gate->attempt($lead, '10.0.0.1', 4)->allowed)->toBeTrue();
    // Now nothing more fits — not even one more credit.
    expect($gate->attempt($lead, '10.0.0.2', 1)->allowed)->toBeFalse();
    expect((int) cache()->get('generation:global'))->toBe(4);
});

it('counts a multi-unit request (an outfit) as N against the per-user daily limit', function () {
    // Per-user limit is a count of GENERATIONS; an N-garment outfit is N of them,
    // checked whole — a set must not be a loophole around the daily flood guard.
    config(['fitting_room.generation.global_cap' => 1000, 'fitting_room.generation.per_user_daily_limit' => 3]);

    $gate = app(GenerationGate::class);
    $lead = Lead::factory()->create();

    // A 2-unit outfit consumes 2 of the 3 daily slots.
    expect($gate->attempt($lead, '10.0.0.1', cost: 2, units: 2)->allowed)->toBeTrue();

    // Another 2-unit set would need 4 of 3 → rejected as a 429, nothing claimed.
    $rejection = $gate->attempt($lead, '10.0.0.1', cost: 2, units: 2);
    expect($rejection->allowed)->toBeFalse()
        ->and($rejection->status)->toBe(429);

    // A single try-on (1 unit) still fits the remaining slot.
    expect($gate->attempt($lead, '10.0.0.1', cost: 1, units: 1)->allowed)->toBeTrue();
});

it('refuses an over-cap request without sterilising capacity for cheaper ones', function () {
    config(['fitting_room.generation.global_cap' => 4, 'fitting_room.generation.per_user_daily_limit' => 1000]);

    $gate = app(GenerationGate::class);
    $lead = Lead::factory()->create();

    // An 8-credit request can never fit a cap of 4 → rejected, and it must NOT
    // leave the counter inflated (no permanently lost capacity).
    expect($gate->attempt($lead, '10.0.0.1', 8)->allowed)->toBeFalse();
    expect((int) cache()->get('generation:global'))->toBe(0);

    // A 4-credit request still fits afterwards — capacity was preserved.
    expect($gate->attempt($lead, '10.0.0.2', 4)->allowed)->toBeTrue();
});
