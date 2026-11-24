<?php

use App\Services\Fashn\FashnTryOnProvider;
use App\Services\Fashn\TryOnException;
use Illuminate\Support\Facades\Http;

/*
 * FASHN provider — request shaping and response parsing. FASHN is ALWAYS faked;
 * not a single real call leaves the test (no credits spent, no key needed).
 */

beforeEach(function () {
    config(['services.fashn.key' => 'test-key']);
});

function provider(): FashnTryOnProvider
{
    return app(FashnTryOnProvider::class);
}

it('posts a correct /v1/run request with the bearer key and tryon-max inputs', function () {
    Http::fake(['*/run' => Http::response(['id' => 'pred_123', 'error' => null], 200)]);

    $id = provider()->run('https://person.test/me.jpg', 'https://garment.test/dress.jpg');

    expect($id)->toBe('pred_123');

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/run')
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && data_get($request->data(), 'model_name') === 'tryon-max'
            // model_image is the PERSON; product_image is the catalog item.
            && data_get($request->data(), 'inputs.model_image') === 'https://person.test/me.jpg'
            && data_get($request->data(), 'inputs.product_image') === 'https://garment.test/dress.jpg'
            // Quality lever for tryon-max; default 'quality'.
            && data_get($request->data(), 'inputs.generation_mode') === 'quality'
            // tryon-max auto-detects category and returns one image — none of the
            // tryon-v1.6 fields are sent.
            && data_get($request->data(), 'inputs.category') === null
            && data_get($request->data(), 'inputs.num_samples') === null
            && data_get($request->data(), 'inputs.moderation_level') === null;
    });
});

it('sends the configured generation_mode in the run request (configurable)', function () {
    Http::fake(['*/run' => Http::response(['id' => 'pred_mode'], 200)]);

    // Default is 'quality'; prove it is config-driven, not hardcoded.
    config(['services.fashn.mode' => 'balanced']);

    provider()->run('https://person.test/me.jpg', 'https://garment.test/dress.jpg');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/run')
        && data_get($request->data(), 'inputs.generation_mode') === 'balanced');
});

it('posts a background-change run on the same endpoint with the source image and prompt', function () {
    Http::fake(['*/run' => Http::response(['id' => 'bg_123', 'error' => null], 200)]);

    $id = provider()->changeBackground('https://cdn.fashn.test/look.png', 'Dubai Marina, street style');

    expect($id)->toBe('bg_123');

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/run')
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && data_get($request->data(), 'model_name') === 'background-change'
            && data_get($request->data(), 'inputs.image') === 'https://cdn.fashn.test/look.png'
            && data_get($request->data(), 'inputs.prompt') === 'Dubai Marina, street style';
    });
});

it('throws a TryOnException when a background-change run returns no id', function () {
    Http::fake(['*/run' => Http::response(['error' => ['name' => 'BadRequest']], 400)]);

    expect(fn () => provider()->changeBackground('https://cdn/look.png', 'a beach'))
        ->toThrow(TryOnException::class);
});

it('throws a TryOnException on an API-level rejection (401, no id)', function () {
    Http::fake(['*/run' => Http::response(['error' => ['name' => 'Unauthorized']], 401)]);

    expect(fn () => provider()->run('https://person', 'https://garment'))
        ->toThrow(TryOnException::class);
});

it('throws when /v1/run returns no prediction id', function () {
    Http::fake(['*/run' => Http::response(['id' => null, 'error' => null], 200)]);

    expect(fn () => provider()->run('https://person', 'https://garment'))
        ->toThrow(TryOnException::class);
});

it('parses a completed status with output urls', function () {
    Http::fake(['*/status/*' => Http::response([
        'id' => 'pred_123',
        'status' => 'completed',
        'output' => ['https://cdn.fashn.test/out.png'],
        'error' => null,
    ], 200)]);

    $status = provider()->status('pred_123');

    expect($status->isCompleted())->toBeTrue()
        ->and($status->isTerminal())->toBeTrue()
        ->and($status->outputUrls)->toBe(['https://cdn.fashn.test/out.png'])
        ->and($status->error)->toBeNull();
});

it('parses a failed status with an error message', function () {
    Http::fake(['*/status/*' => Http::response([
        'id' => 'pred_123',
        'status' => 'failed',
        'output' => [],
        'error' => ['name' => 'ContentModerationError', 'message' => 'blocked content'],
    ], 200)]);

    $status = provider()->status('pred_123');

    expect($status->isFailed())->toBeTrue()
        ->and($status->outputUrls)->toBe([])
        ->and($status->error)->toBe('blocked content');
});

it('throws when the status request itself fails', function () {
    Http::fake(['*/status/*' => Http::response([], 500)]);

    expect(fn () => provider()->status('pred_123'))->toThrow(TryOnException::class);
});
