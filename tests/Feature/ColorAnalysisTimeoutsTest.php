<?php

/*
 * Three timeouts govern one colour analysis, and their ORDER is what keeps the
 * failure mode sane:
 *
 *   HTTP timeout  <  job timeout  <  queue retry_after
 *
 * Read from the inside out: the socket gives up first, so the job catches a
 * transport error and records a failure somebody can read. If the worker
 * timeout came first the job would be killed mid-call with nothing written
 * down. If retry_after came before the worker timeout, a job still running
 * would be handed to a second worker — two analyses of one photo, racing to
 * write the same row.
 *
 * These are cheap guards on the ordering, not on the particular numbers: every
 * one is env-driven and expected to be tuned on a real box.
 */

function httpTimeout(): int
{
    return (int) config('services.pose.color_timeout');
}

function jobTimeout(): int
{
    return (int) config('horizon.defaults.supervisor-color.timeout');
}

function retryAfter(): int
{
    return (int) config('queue.connections.redis_color.retry_after');
}

it('gives the socket the shortest rope', function () {
    expect(httpTimeout())->toBeGreaterThan(0)
        ->and(httpTimeout())->toBeLessThan(jobTimeout());
});

it('leaves the worker room for more than one HTTP call', function () {
    /* The job reads the photo from storage, calls the service, then writes the
     * rendered views back. The call is the long part but it is not the only
     * part. */
    expect(jobTimeout())->toBeGreaterThanOrEqual(2 * httpTimeout());
});

it('never lets the queue re-dispatch a job that is still running', function () {
    /* The one that would cost real money elsewhere and costs a corrupted row
     * here: two workers analysing the same photo. */
    expect(retryAfter())->toBeGreaterThan(jobTimeout());
});

it('keeps the colour queue off the try-on connection', function () {
    /* Its own connection and its own queue, so retuning any of these numbers
     * cannot change how a try-on behaves. (The two retry_after values happen to
     * match today — that is a coincidence of scale, not a dependency.) */
    expect(config('color_analysis.queue_connection'))->toBe('redis_color')
        ->and(config('queue.connections.redis_color.queue'))->toBe('color')
        ->and(config('queue.connections.redis_color.connection'))
        ->toBe(config('queue.connections.redis.connection'));
});

it('ships the documented defaults', function () {
    /* A regression guard on the values the README and .env.example quote. */
    expect(httpTimeout())->toBe(60)
        ->and(jobTimeout())->toBe(120)
        ->and(retryAfter())->toBe(180);
});

it('runs the colour supervisor on its own queue, niced below try-on work', function () {
    $supervisor = config('horizon.defaults.supervisor-color');

    expect($supervisor['queue'])->toBe(['color'])
        ->and($supervisor['connection'])->toBe('redis_color')
        ->and($supervisor['nice'])->toBeGreaterThan(0);
});
