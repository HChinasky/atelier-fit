<?php

use App\Services\Photo\PhotoEnhancementException;
use App\Services\Photo\PoseServiceImageEnhancer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * The HTTP enhancer (9.5) that calls the pose microservice's /preprocess-image.
 * The service is ALWAYS faked — no live call. Covers request shaping, the
 * applied-step headers, fail-open on failure, and the privacy invariant (no image
 * bytes ever logged).
 */

beforeEach(function () {
    config([
        'services.pose.url' => 'http://pose.test:8000',
        'services.pose.token' => '',
        'photo.enhancement.lighting.enabled' => true,
        'photo.enhancement.segmentation.enabled' => true,
        'photo.enhancement.timeout' => 30,
    ]);
});

it('sends the photo as multipart with both step flags and returns the enhanced bytes', function () {
    Http::fake([
        '*/preprocess-image' => Http::response('ENHANCED-BYTES', 200, [
            'X-Applied-Lighting' => 'true',
            'X-Applied-Segment' => 'true',
        ]),
    ]);

    $result = app(PoseServiceImageEnhancer::class)->enhance('ORIGINAL-BYTES', 'jpeg');

    expect($result->contents)->toBe('ENHANCED-BYTES')
        ->and($result->format)->toBe('jpeg')
        ->and($result->lightingApplied)->toBeTrue()
        ->and($result->segmentApplied)->toBeTrue();

    Http::assertSent(function (Request $request) {
        return $request->url() === 'http://pose.test:8000/preprocess-image'
            && $request->isMultipart()
            && collect($request->data())->contains(fn ($p) => $p['name'] === 'lighting' && $p['contents'] === 'true')
            && collect($request->data())->contains(fn ($p) => $p['name'] === 'segment' && $p['contents'] === 'true')
            && collect($request->data())->contains(fn ($p) => $p['name'] === 'image');
    });
});

it('reflects disabled steps in the flags it sends', function () {
    config(['photo.enhancement.segmentation.enabled' => false]);
    Http::fake(['*/preprocess-image' => Http::response('X', 200, ['X-Applied-Lighting' => 'true', 'X-Applied-Segment' => 'false'])]);

    app(PoseServiceImageEnhancer::class)->enhance('bytes', 'jpeg');

    Http::assertSent(fn (Request $request) => collect($request->data())
        ->contains(fn ($p) => $p['name'] === 'segment' && $p['contents'] === 'false'));
});

it('attaches the X-API-Token header when a token is configured', function () {
    config(['services.pose.token' => 'secret-token']);
    Http::fake(['*/preprocess-image' => Http::response('X', 200)]);

    app(PoseServiceImageEnhancer::class)->enhance('bytes', 'jpeg');

    Http::assertSent(fn (Request $request) => $request->hasHeader('X-API-Token', 'secret-token'));
});

it('throws (fail-open signal) on a transport error, logging no image content', function () {
    Http::fake(fn () => throw new ConnectionException('connection refused'));
    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = $message->message;
    });

    expect(fn () => app(PoseServiceImageEnhancer::class)->enhance('SECRET-PHOTO-BYTES', 'jpeg'))
        ->toThrow(PhotoEnhancementException::class);

    expect($logged)->toContain('[enhance] preprocess request failed: transport error');
    // Privacy: the photo bytes never appear in any log line.
    foreach ($logged as $line) {
        expect($line)->not->toContain('SECRET-PHOTO-BYTES');
    }
});

it('throws on a non-2xx response', function () {
    Http::fake(['*/preprocess-image' => Http::response('boom', 500)]);

    expect(fn () => app(PoseServiceImageEnhancer::class)->enhance('bytes', 'jpeg'))
        ->toThrow(PhotoEnhancementException::class);
});

it('throws on an empty body', function () {
    Http::fake(['*/preprocess-image' => Http::response('', 200)]);

    expect(fn () => app(PoseServiceImageEnhancer::class)->enhance('bytes', 'jpeg'))
        ->toThrow(PhotoEnhancementException::class);
});
