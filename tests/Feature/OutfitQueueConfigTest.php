<?php

/*
 * The outfit chain runs on a DEDICATED queue/connection with a long timeout, so a
 * multi-step chain is never killed mid-poll (which would wrongly fail it) nor
 * re-dispatched while still running (which would double-spend). These invariants
 * are config-level, so we assert them directly — they must hold as the chain grows.
 */

it('keeps the outfit connection retry_after ABOVE the outfit job timeout (no mid-poll re-dispatch)', function () {
    $jobTimeout = (int) config('fitting_room.outfit.job_timeout');
    $retryAfter = (int) config('queue.connections.redis_outfits.retry_after');

    // retry_after must exceed the worker timeout, or a still-running chain is
    // released and a second worker re-runs it — duplicating billed engine calls.
    expect($retryAfter)->toBeGreaterThan($jobTimeout);
});

it('routes the outfit queue/connection consistently across config', function () {
    expect((string) config('queue.connections.redis_outfits.queue'))
        ->toBe((string) config('fitting_room.outfit.queue'))
        ->and((string) config('fitting_room.outfit.queue_connection'))->toBe('redis_outfits');
});

it('gives the outfit a dedicated Horizon supervisor whose worker timeout matches the job timeout and stays under retry_after', function () {
    $supervisor = config('horizon.defaults.supervisor-outfit');
    $jobTimeout = (int) config('fitting_room.outfit.job_timeout');
    $retryAfter = (int) config('queue.connections.redis_outfits.retry_after');

    expect($supervisor)->not->toBeNull()
        ->and($supervisor['connection'])->toBe('redis_outfits')
        ->and($supervisor['queue'])->toContain(config('fitting_room.outfit.queue'))
        ->and($supervisor['tries'])->toBe(1)
        // Worker timeout == job timeout, and both below retry_after.
        ->and((int) $supervisor['timeout'])->toBe($jobTimeout)
        ->and((int) $supervisor['timeout'])->toBeLessThan($retryAfter);

    // The single-try-on worker is untouched (its own tight timeout).
    expect((int) config('horizon.defaults.supervisor-1.timeout'))->toBe(150);
});
