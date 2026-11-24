<?php

use App\Services\Fal\FalTryOnProvider;
use App\Services\Fashn\FashnTryOnProvider;
use App\Services\Fashn\TryOnException;
use App\Services\Fashn\TryOnProvider;
use Illuminate\Support\Facades\Http;

/*
 * fal.ai provider — request shaping and the submit -> status -> result queue flow.
 * fal is ALWAYS faked; not a single real call leaves the test (no spend, no key
 * needed). The provider implements the SAME TryOnProvider contract as FASHN.
 *
 * Fake-matching order matters: the status URL (.../requests/{id}/status) must be
 * listed BEFORE the result URL (.../requests/{id}), since the result pattern would
 * otherwise also match the status URL (first match wins).
 */

beforeEach(function () {
    config([
        'services.fal.key' => 'test-fal-key',
        'services.fal.base_url' => 'https://queue.fal.run',
        'services.fal.model' => 'fal-ai/image-apps-v2/virtual-try-on',
        'services.fal.app_id' => null,
    ]);
});

function falProvider(): FalTryOnProvider
{
    return app(FalTryOnProvider::class);
}

it('submits person/clothing image urls with the Key auth header and returns the request id', function () {
    Http::fake(['*/virtual-try-on' => Http::response(['request_id' => 'req_123', 'status' => 'IN_QUEUE'], 200)]);

    $id = falProvider()->run('https://person.test/me.jpg', 'https://garment.test/dress.jpg', ['category' => 'tops']);

    expect($id)->toBe('req_123');

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/fal-ai/image-apps-v2/virtual-try-on')
            && $request->hasHeader('Authorization', 'Key test-fal-key')
            // Our person photo -> person_image_url; our garment -> clothing_image_url.
            && data_get($request->data(), 'person_image_url') === 'https://person.test/me.jpg'
            && data_get($request->data(), 'clothing_image_url') === 'https://garment.test/dress.jpg'
            // image-apps-v2 auto-detects: no category / num_samples sent to fal.
            && data_get($request->data(), 'category') === null
            && data_get($request->data(), 'num_samples') === null
            // preserve_pose is sent EXPLICITLY (default true) to curb pose drift...
            && data_get($request->data(), 'preserve_pose') === true
            // ...and aspect_ratio is NOT sent unless configured (fal's own default).
            && data_get($request->data(), 'aspect_ratio') === null;
    });
});

it('sends preserve_pose=false and a forced aspect_ratio when configured', function () {
    config(['services.fal.preserve_pose' => false, 'services.fal.aspect_ratio' => '4:3']);
    Http::fake(['*/virtual-try-on' => Http::response(['request_id' => 'req_cfg'], 200)]);

    falProvider()->run('https://person.test/me.jpg', 'https://garment.test/dress.jpg');

    Http::assertSent(function ($request) {
        return data_get($request->data(), 'preserve_pose') === false
            && data_get($request->data(), 'aspect_ratio') === '4:3';
    });
});

it('omits aspect_ratio when it is blank/whitespace (lets fal use its default)', function () {
    config(['services.fal.aspect_ratio' => '   ']);
    Http::fake(['*/virtual-try-on' => Http::response(['request_id' => 'req_blank'], 200)]);

    falProvider()->run('https://person', 'https://garment');

    Http::assertSent(fn ($request) => data_get($request->data(), 'aspect_ratio') === null
        // preserve_pose still rides along.
        && data_get($request->data(), 'preserve_pose') === true);
});

it('throws a TryOnException when submit is rejected (401, no request id)', function () {
    Http::fake(['*/virtual-try-on' => Http::response(['detail' => 'Unauthorized'], 401)]);

    expect(fn () => falProvider()->run('https://person', 'https://garment'))
        ->toThrow(TryOnException::class);
});

it('throws a TryOnException when submit returns no request id', function () {
    Http::fake(['*/virtual-try-on' => Http::response(['status' => 'IN_QUEUE'], 200)]);

    expect(fn () => falProvider()->run('https://person', 'https://garment'))
        ->toThrow(TryOnException::class);
});

it('reports a non-terminal queue state as processing (keeps polling)', function () {
    Http::fake(['*/requests/*/status' => Http::response(['status' => 'IN_PROGRESS'], 200)]);

    $status = falProvider()->status('req_123');

    expect($status->isTerminal())->toBeFalse()
        ->and($status->isCompleted())->toBeFalse()
        ->and($status->outputUrls)->toBe([]);
});

it('on COMPLETED, fetches the result and returns the image url', function () {
    Http::fake([
        '*/requests/*/status' => Http::response(['status' => 'COMPLETED'], 200),
        '*/requests/*' => Http::response(['image' => ['url' => 'https://fal.media/out.png']], 200),
    ]);

    $status = falProvider()->status('req_123');

    expect($status->isCompleted())->toBeTrue()
        ->and($status->isTerminal())->toBeTrue()
        ->and($status->outputUrls)->toBe(['https://fal.media/out.png'])
        ->and($status->error)->toBeNull();

    // It addressed the APP id (first two model segments), not the full model path.
    Http::assertSent(fn ($request) => str_contains($request->url(), '/fal-ai/image-apps-v2/requests/req_123'));
});

it('also accepts an images[0].url result shape', function () {
    Http::fake([
        '*/requests/*/status' => Http::response(['status' => 'COMPLETED'], 200),
        '*/requests/*' => Http::response(['images' => [['url' => 'https://fal.media/a.png']]], 200),
    ]);

    expect(falProvider()->status('req_1')->outputUrls)->toBe(['https://fal.media/a.png']);
});

it('marks the result failed (terminal) when the completed result carries no image url', function () {
    Http::fake([
        '*/requests/*/status' => Http::response(['status' => 'COMPLETED'], 200),
        '*/requests/*' => Http::response(['detail' => 'content rejected'], 200),
    ]);

    $status = falProvider()->status('req_123');

    expect($status->isFailed())->toBeTrue()
        ->and($status->outputUrls)->toBe([])
        ->and($status->error)->toBe('content rejected');
});

it('treats a rejected result response as a terminal failure (not an infinite poll)', function () {
    Http::fake([
        '*/requests/*/status' => Http::response(['status' => 'COMPLETED'], 200),
        '*/requests/*' => Http::response(['detail' => 'generation error'], 422),
    ]);

    $status = falProvider()->status('req_123');

    expect($status->isFailed())->toBeTrue()
        ->and($status->error)->toBe('generation error');
});

it('throws a TryOnException when the status request itself fails at the API level', function () {
    Http::fake(['*/requests/*/status' => Http::response([], 500)]);

    expect(fn () => falProvider()->status('req_123'))->toThrow(TryOnException::class);
});

it('sanitizes a fal error that echoes a tokenized url — no token/url leaks', function () {
    Http::fake([
        '*/requests/*/status' => Http::response(['status' => 'COMPLETED'], 200),
        '*/requests/*' => Http::response([
            'detail' => 'input rejected, ref https://r2.test/uploads/abc.jpg?X-Amz-Signature=SECRETTOKEN',
        ], 422),
    ]);

    $status = falProvider()->status('req_123');

    expect($status->isFailed())->toBeTrue()
        // The safe, descriptive part survives...
        ->and($status->error)->toContain('input rejected')
        // ...but the URL and the token never do.
        ->and($status->error)->not->toContain('SECRETTOKEN')
        ->and($status->error)->not->toContain('X-Amz-Signature')
        ->and($status->error)->not->toContain('https://r2.test');
});

it('does not support background change (throws so the job keeps the original look)', function () {
    expect(fn () => falProvider()->changeBackground('https://fal.media/look.png', 'a beach'))
        ->toThrow(TryOnException::class);
});

it('resolves the TryOnProvider contract by the configured driver (fal | fashn)', function () {
    config(['services.tryon.driver' => 'fal']);
    expect(app(TryOnProvider::class))->toBeInstanceOf(FalTryOnProvider::class);

    config(['services.tryon.driver' => 'fashn']);
    expect(app(TryOnProvider::class))->toBeInstanceOf(FashnTryOnProvider::class);
});

/*
 * --- what fal keeps on its own side ---
 *
 * Left alone, fal stores request payloads for 30 days and serves generated media
 * from a public url with no expiry. Both are set per request, so every call has
 * to carry the headers — a call site that forgets them silently opts out.
 */

it('tells fal not to store the request payloads', function () {
    Http::fake(['*' => Http::response(['request_id' => 'req_1'], 200)]);

    app(FalTryOnProvider::class)->run('https://r2.test/photo.jpg?token=X', 'https://garment.test/x.jpg');

    // The payload carries the signed photo url; a 30-day copy of it is exactly
    // what this header prevents.
    Http::assertSent(fn ($request) => $request->header('X-Fal-Store-IO') === ['0']);
});

it('expires the generated media on fal after the configured lifetime', function () {
    config(['services.fal.media_ttl_seconds' => 259200]);
    Http::fake(['*' => Http::response(['request_id' => 'req_1'], 200)]);

    app(FalTryOnProvider::class)->run('https://r2.test/photo.jpg', 'https://garment.test/x.jpg');

    Http::assertSent(function ($request) {
        $lifecycle = json_decode($request->header('X-Fal-Object-Lifecycle-Preference')[0] ?? '{}', true);

        return ($lifecycle['expiration_duration_seconds'] ?? null) === 259200;
    });
});

it('keeps the generated media readable only by us', function () {
    Http::fake(['*' => Http::response(['request_id' => 'req_1'], 200)]);

    app(FalTryOnProvider::class)->run('https://r2.test/photo.jpg', 'https://garment.test/x.jpg');

    Http::assertSent(function ($request) {
        $lifecycle = json_decode($request->header('X-Fal-Object-Lifecycle-Preference')[0] ?? '{}', true);

        // "forbid" is a 403 to everyone else; we keep access as the owner.
        return ($lifecycle['initial_acl']['default'] ?? null) === 'forbid';
    });
});

it('drops the ACL but keeps the expiry when private media is switched off', function () {
    config(['services.fal.private_media' => false]);
    Http::fake(['*' => Http::response(['request_id' => 'req_1'], 200)]);

    app(FalTryOnProvider::class)->run('https://r2.test/photo.jpg', 'https://garment.test/x.jpg');

    Http::assertSent(function ($request) {
        $lifecycle = json_decode($request->header('X-Fal-Object-Lifecycle-Preference')[0] ?? '{}', true);

        return ! isset($lifecycle['initial_acl'])
            && isset($lifecycle['expiration_duration_seconds']);
    });
});

it('exchanges the api key for a cdn token before downloading a private result', function () {
    Http::fake([
        '*storage/auth/token*' => Http::response(['token' => 'cdn-token-abc'], 200),
        'https://v3.fal.media/*' => Http::response('LOOK-BYTES', 200),
    ]);

    $bytes = app(FalTryOnProvider::class)->downloadResult('https://v3.fal.media/files/look.png');

    expect($bytes)->toBe('LOOK-BYTES');

    // The CDN refuses the API key itself; it wants a token that identifies the
    // owner of the file.
    Http::assertSent(fn ($request) => str_contains($request->url(), 'v3.fal.media')
        && $request->header('Authorization') === ['Bearer cdn-token-abc']);
});

it('still attempts the download when the token exchange fails', function () {
    // A public-media configuration, or a changed token endpoint, must not turn
    // every generation into a failure.
    Http::fake([
        '*storage/auth/token*' => Http::response([], 500),
        'https://v3.fal.media/*' => Http::response('LOOK-BYTES', 200),
    ]);

    expect(app(FalTryOnProvider::class)->downloadResult('https://v3.fal.media/files/look.png'))
        ->toBe('LOOK-BYTES');
});
