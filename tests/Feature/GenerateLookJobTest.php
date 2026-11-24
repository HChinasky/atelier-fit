<?php

use App\Jobs\GenerateLookJob;
use App\Models\Generation;
use App\Models\Lead;
use App\Models\Product;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Monolog\Handler\TestHandler;

/*
 * GenerateLookJob — run → poll → done/failed, with FASHN faked end to end and
 * the client photo reached through a (faked) pre-signed R2 URL. No real FASHN or
 * R2 calls; Sleep is faked so polling waits cost nothing.
 */

beforeEach(function () {
    config(['services.fashn.key' => 'test-key']);
    Storage::fake('r2');
    // Deterministic pre-signed URL with a token, so we can assert it never leaks.
    Storage::disk('r2')->buildTemporaryUrlsUsing(
        fn ($path, $expiration, $options = []) => 'https://r2.test/'.$path.'?X-Amz-Token=SECRETTOKEN'
    );
    Sleep::fake();
});

/**
 * The recorded looks with their R2 keys resolved. Every finished look is copied
 * off the engine CDN into OUR private R2 before it is recorded, so each stored
 * url must be a signed R2 url — never a vendor CDN one.
 *
 * @return list<array{key: string, with_background: bool}>
 */
function storedLooks(Generation $generation): array
{
    return collect($generation->refresh()->result_urls ?? [])
        ->map(function (array $look) {
            expect($look['url'])->toStartWith('https://r2.test/uploads/2026-06-12/')
                ->and($look['url'])->toContain('X-Amz-Token=SECRETTOKEN');

            return [
                'key' => Str::before(Str::after($look['url'], 'https://r2.test/'), '?'),
                'with_background' => $look['with_background'],
            ];
        })
        ->all();
}

/** Assert the engine CDN url was actually fetched (i.e. the look was pulled in). */
function assertLookDownloaded(string $cdnUrl): void
{
    Http::assertSent(fn ($request) => $request->url() === $cdnUrl && $request->method() === 'GET');
}

function makeGeneration(string $category = 'upper_body', string $garment = 'https://garment.test/x.jpg'): Generation
{
    $product = Product::factory()->create(['category' => $category, 'image_flat_url' => $garment]);

    return Generation::factory()->create([
        'lead_id' => Lead::factory()->create()->id,
        'product_id' => $product->id,
        'client_photo' => 'uploads/2026-06-12/abc.jpg',
        'status' => 'queued',
    ]);
}

it('is queued (ShouldQueue), not run synchronously by design', function () {
    expect(is_subclass_of(GenerateLookJob::class, ShouldQueue::class))->toBeTrue();
});

it('refuses to run without a captured lead and never calls FASHN', function () {
    Http::fake();

    $product = Product::factory()->create();
    $generation = Generation::factory()->create([
        'lead_id' => null,
        'product_id' => $product->id,
        'status' => 'queued',
    ]);

    GenerateLookJob::dispatchSync($generation);

    Http::assertNothingSent();
    expect($generation->refresh()->status)->toBe('failed');
});

it('runs the happy path: submits, stores the prediction id, polls to done', function () {
    Http::fake([
        '*/run' => Http::response(['id' => 'pred_1', 'error' => null], 200),
        '*/status/*' => Http::response([
            'id' => 'pred_1', 'status' => 'completed',
            'output' => ['https://cdn.fashn.test/look.png'], 'error' => null,
        ], 200),
        'https://cdn.fashn.test/*' => Http::response('LOOK-BYTES', 200),
    ]);

    $generation = makeGeneration('upper_body', 'https://garment.test/shirt.jpg');

    GenerateLookJob::dispatchSync($generation);

    $generation->refresh();
    expect($generation->status)->toBe('done')
        ->and($generation->fashn_prediction_id)->toBe('pred_1')
        ->and($generation->error)->toBeNull();

    // The look was pulled off the engine CDN and stored in OUR private R2; what is
    // recorded is a signed R2 url, never the vendor's.
    assertLookDownloaded('https://cdn.fashn.test/look.png');

    $looks = storedLooks($generation);
    expect($looks)->toHaveCount(1)
        ->and($looks[0]['with_background'])->toBeFalse();

    Storage::disk('r2')->assertExists($looks[0]['key']);
    expect(Storage::disk('r2')->get($looks[0]['key']))->toBe('LOOK-BYTES');
    expect(json_encode($generation->result_urls))->not->toContain('cdn.fashn.test');

    // The person photo went out as the pre-signed URL; tryon-max inputs only.
    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/run')
            && data_get($request->data(), 'model_name') === 'tryon-max'
            && data_get($request->data(), 'inputs.model_image') === 'https://r2.test/uploads/2026-06-12/abc.jpg?X-Amz-Token=SECRETTOKEN'
            && data_get($request->data(), 'inputs.product_image') === 'https://garment.test/shirt.jpg'
            // Job forwards the config quality lever (default 'quality'); tryon-max
            // auto-detects the category, so none is sent.
            && data_get($request->data(), 'inputs.generation_mode') === 'quality'
            && data_get($request->data(), 'inputs.category') === null;
    });
});

it('handles an intermediate processing status before completing, and waits between polls', function () {
    Http::fake([
        '*/run' => Http::response(['id' => 'pred_2'], 200),
        '*/status/*' => Http::sequence()
            ->push(['status' => 'processing', 'output' => [], 'error' => null], 200)
            ->push(['status' => 'completed', 'output' => ['https://cdn/done.png'], 'error' => null], 200),
        'https://cdn/*' => Http::response('DONE-BYTES', 200),
    ]);

    $generation = makeGeneration();

    GenerateLookJob::dispatchSync($generation);

    expect($generation->refresh()->status)->toBe('done');

    $looks = storedLooks($generation);
    expect($looks)->toHaveCount(1);
    expect(Storage::disk('r2')->get($looks[0]['key']))->toBe('DONE-BYTES');

    Sleep::assertSleptTimes(1);
});

it('stores the look without the grey margins the aspect fitter added', function () {
    config(['photo.output.aspect' => '3:4', 'photo.output.pad_color' => '#f0f0f0', 'photo.normalization.driver' => 'gd']);
    $manager = new ImageManager(new Driver);
    $padded = $manager->create(300, 400)->fill('#f0f0f0');
    $padded->place($manager->create(180, 400)->fill('#3a2e28'), 'top-left', 60, 0);

    Http::fake([
        '*/run' => Http::response(['id' => 'pred_pad'], 200),
        '*/status/*' => Http::response(['status' => 'completed', 'output' => ['https://cdn/padded.png'], 'error' => null], 200),
        'https://cdn/*' => Http::response((string) $padded->toPng(), 200),
    ]);

    $generation = makeGeneration();

    GenerateLookJob::dispatchSync($generation);

    $stored = $manager->read(Storage::disk('r2')->get(storedLooks($generation)[0]['key']));
    expect([$stored->width(), $stored->height()])->toBe([180, 400]);
});

it('marks the generation failed on a runtime failure, with no result urls', function () {
    Http::fake([
        '*/run' => Http::response(['id' => 'pred_3'], 200),
        '*/status/*' => Http::response([
            'status' => 'failed', 'output' => [],
            'error' => ['name' => 'ModerationError', 'message' => 'blocked content'],
        ], 200),
    ]);

    $generation = makeGeneration();

    GenerateLookJob::dispatchSync($generation);

    $generation->refresh();
    expect($generation->status)->toBe('failed')
        ->and($generation->error)->toBe('blocked content')
        ->and($generation->result_urls)->toBeNull();
});

it('marks the generation failed on an API-level error without throwing', function () {
    Http::fake(['*/run' => Http::response(['error' => ['name' => 'Unauthorized']], 401)]);

    $generation = makeGeneration();

    GenerateLookJob::dispatchSync($generation);

    $generation->refresh();
    expect($generation->status)->toBe('failed')
        ->and($generation->fashn_prediction_id)->toBeNull()
        ->and($generation->error)->not->toBeNull();
});

it('times out after the max polling attempts instead of looping forever', function () {
    config(['services.fashn.poll.max_attempts' => 3, 'services.fashn.poll.interval_seconds' => 1]);

    Http::fake([
        '*/run' => Http::response(['id' => 'pred_4'], 200),
        '*/status/*' => Http::response(['status' => 'processing', 'output' => []], 200),
    ]);

    $generation = makeGeneration();

    GenerateLookJob::dispatchSync($generation);

    $generation->refresh();
    expect($generation->status)->toBe('failed')
        ->and($generation->error)->toContain('timed out');

    Sleep::assertSleptTimes(3);
});

it('sanitizes an external FASHN error that echoes a tokenized url, keeping the safe code', function () {
    $generation = makeGeneration();

    // FASHN (untrusted) echoes back a pre-signed URL with a token AND the photo key.
    $leakedUrl = 'https://r2.test/'.$generation->client_photo.'?X-Amz-Signature=SECRETTOKEN';

    Http::fake([
        '*/run' => Http::response(['id' => 'pred_leak'], 200),
        '*/status/*' => Http::response([
            'status' => 'failed',
            'output' => [],
            'error' => [
                'name' => 'ContentModerationError',
                'message' => 'ContentModerationError: input rejected, ref '.$leakedUrl,
            ],
        ], 200),
    ]);

    $handler = new TestHandler;
    Log::driver()->getLogger()->pushHandler($handler);

    GenerateLookJob::dispatchSync($generation);
    $generation->refresh();

    // Safe diagnostic survives.
    expect($generation->status)->toBe('failed')
        ->and($generation->error)->toContain('ContentModerationError');

    // The token / URL / photo key never reach the persisted error.
    expect($generation->error)->not->toContain('SECRETTOKEN')
        ->and($generation->error)->not->toContain('X-Amz-Signature')
        ->and($generation->error)->not->toContain('https://r2.test')
        ->and($generation->error)->not->toContain($generation->client_photo);

    // ...nor the logs, which still carry the safe code for diagnostics.
    $logged = false;
    foreach ($handler->getRecords() as $record) {
        $blob = $record->message.' '.json_encode($record->context);
        expect($blob)->not->toContain('SECRETTOKEN')
            ->and($blob)->not->toContain('X-Amz-Signature')
            ->and($blob)->not->toContain($generation->client_photo);
        if (str_contains($blob, 'ContentModerationError')) {
            $logged = true;
        }
    }
    expect($logged)->toBeTrue();
});

it('never leaks the photo key or the signed-url token into the logs', function () {
    Http::fake([
        '*/run' => Http::response(['id' => 'pred_5'], 200),
        '*/status/*' => Http::response([
            'status' => 'failed', 'output' => [], 'error' => ['message' => 'blocked content'],
        ], 200),
    ]);

    $handler = new TestHandler;
    Log::driver()->getLogger()->pushHandler($handler);

    $generation = makeGeneration();
    GenerateLookJob::dispatchSync($generation);

    foreach ($handler->getRecords() as $record) {
        $blob = $record->message.' '.json_encode($record->context);
        expect($blob)->not->toContain('SECRETTOKEN')
            ->and($blob)->not->toContain('X-Amz-Token')
            ->and($blob)->not->toContain($generation->client_photo);
    }
});

/*
 * --- Phase 5: multiple looks (num_samples) + optional background change ---
 */

// Count how many /v1/run requests carried a given model_name.
function runsForModel(string $model): int
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/run')
            && data_get($pair[0]->data(), 'model_name') === $model)
        ->count();
}

function makeMultiLook(int $numSamples, bool $withBackground = false): Generation
{
    $factory = Generation::factory()->looks($numSamples);
    if ($withBackground) {
        $factory = $factory->withBackground();
    }

    return $factory->create([
        'lead_id' => Lead::factory()->create()->id,
        'product_id' => Product::factory()->create()->id,
        'client_photo' => 'uploads/2026-06-12/abc.jpg',
        'status' => 'queued',
    ]);
}

it('stores every look the engine returns', function () {
    Http::fake([
        '*/run' => Http::response(['id' => 'pred_multi'], 200),
        '*/status/*' => Http::response([
            'status' => 'completed',
            'output' => ['https://cdn/a.png', 'https://cdn/b.png', 'https://cdn/c.png'],
        ], 200),
        'https://cdn/*' => Http::response('LOOK-BYTES', 200),
    ]);

    // tryon-max returns one image per call, but the job maps ALL outputs the
    // engine returns (defensive), so multiple looks are each stored in R2.
    GenerateLookJob::dispatchSync(makeMultiLook(3));

    foreach (['a', 'b', 'c'] as $name) {
        assertLookDownloaded('https://cdn/'.$name.'.png');
    }

    $looks = storedLooks(Generation::sole());
    expect($looks)->toHaveCount(3);

    foreach ($looks as $look) {
        Storage::disk('r2')->assertExists($look['key']);
        expect($look['with_background'])->toBeFalse();
    }

    // Three distinct objects, not one overwritten three times.
    expect(collect($looks)->pluck('key')->unique())->toHaveCount(3);
});

it('fails the generation instead of falling back to the vendor CDN when a look cannot be retrieved', function () {
    Http::fake([
        '*/run' => Http::response(['id' => 'pred_dl'], 200),
        '*/status/*' => Http::response(['status' => 'completed', 'output' => ['https://cdn/gone.png']], 200),
        'https://cdn/*' => Http::response('nope', 502),
    ]);

    $generation = makeGeneration();

    GenerateLookJob::dispatchSync($generation);

    // No silent fallback: leaving the result on the vendor CDN would put it beyond
    // our 72h lifecycle rule, which is exactly what the privacy policy promises.
    $generation->refresh();
    expect($generation->status)->toBe('failed')
        ->and($generation->result_urls)->toBeNull()
        ->and($generation->error)->toContain('could not retrieve');
});

it('does not call background-change when the generation flag is off (pipeline is try-on only)', function () {
    // Feature available in config, but the generation did not ask for it.
    config(['services.fashn.background.enabled' => true]);
    Http::fake([
        '*/run' => Http::response(['id' => 'tryon_1'], 200),
        '*/status/*' => Http::response(['status' => 'completed', 'output' => ['https://cdn/look.png']], 200),
        'https://cdn/*' => Http::response('LOOK-BYTES', 200),
    ]);

    GenerateLookJob::dispatchSync(makeMultiLook(2, withBackground: false));

    $looks = storedLooks(Generation::sole());
    expect(runsForModel('background-change'))->toBe(0)
        ->and($looks)->toHaveCount(1)
        ->and($looks[0]['with_background'])->toBeFalse();
});

it('does not call background-change when the feature is disabled in config, even if the generation flag is set', function () {
    config(['services.fashn.background.enabled' => false]);
    Http::fake([
        '*/run' => Http::response(['id' => 'tryon_1'], 200),
        '*/status/*' => Http::response(['status' => 'completed', 'output' => ['https://cdn/look.png']], 200),
        'https://cdn/*' => Http::response('LOOK-BYTES', 200),
    ]);

    GenerateLookJob::dispatchSync(makeMultiLook(1, withBackground: true));

    $looks = storedLooks(Generation::sole());
    expect(runsForModel('background-change'))->toBe(0)
        ->and($looks)->toHaveCount(1)
        ->and($looks[0]['with_background'])->toBeFalse();
});

it('runs background-change for every look when both the flag and the feature are on', function () {
    config([
        'services.fashn.background.enabled' => true,
        'services.fashn.background.prompts' => ['Dubai Marina, street style'],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();
        if (str_ends_with($url, '/run')) {
            return data_get($request->data(), 'model_name') === 'background-change'
                ? Http::response(['id' => 'bg_1'], 200)
                : Http::response(['id' => 'tryon_1'], 200);
        }
        if (str_contains($url, '/status/bg_')) {
            return Http::response(['status' => 'completed', 'output' => ['https://cdn/bg.png']], 200);
        }
        if (str_starts_with($url, 'https://cdn/')) {
            return Http::response('BG-BYTES', 200);
        }

        return Http::response([
            'status' => 'completed',
            'output' => ['https://cdn/look1.png', 'https://cdn/look2.png'],
        ], 200);
    });

    GenerateLookJob::dispatchSync(makeMultiLook(2, withBackground: true));

    // The background prompt actually reached FASHN, once per look.
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/run')
        && data_get($r->data(), 'model_name') === 'background-change'
        && data_get($r->data(), 'inputs.prompt') === 'Dubai Marina, street style');

    // It is the RE-BACKGROUNDED image that gets stored, not the raw try-on look.
    assertLookDownloaded('https://cdn/bg.png');

    $looks = storedLooks(Generation::sole());
    expect(runsForModel('background-change'))->toBe(2)
        ->and($looks)->toHaveCount(2)
        ->and(collect($looks)->pluck('with_background')->all())->toBe([true, true]);

    foreach ($looks as $look) {
        expect(Storage::disk('r2')->get($look['key']))->toBe('BG-BYTES');
    }
});

it('keeps the original try-on look when its background step fails, without failing the generation (partial success)', function () {
    config([
        'services.fashn.background.enabled' => true,
        'services.fashn.background.prompts' => ['Dubai Marina'],
    ]);

    $bgRun = 0;
    Http::fake(function ($request) use (&$bgRun) {
        $url = $request->url();
        if (str_ends_with($url, '/run')) {
            if (data_get($request->data(), 'model_name') === 'background-change') {
                $bgRun++;

                return Http::response(['id' => 'bg_'.$bgRun], 200);
            }

            return Http::response(['id' => 'tryon_1'], 200);
        }
        if (str_contains($url, '/status/bg_1')) {
            return Http::response(['status' => 'completed', 'output' => ['https://cdn/bg1.png']], 200);
        }
        if (str_contains($url, '/status/bg_2')) {
            // Experimental engine fails on the second look.
            return Http::response(['status' => 'failed', 'output' => [], 'error' => ['message' => 'bg engine error']], 200);
        }
        if (str_starts_with($url, 'https://cdn/')) {
            return Http::response('BYTES-OF-'.basename($url), 200);
        }

        return Http::response([
            'status' => 'completed',
            'output' => ['https://cdn/look1.png', 'https://cdn/look2.png'],
        ], 200);
    });

    GenerateLookJob::dispatchSync(makeMultiLook(2, withBackground: true));

    $generation = Generation::sole()->refresh();

    // Not all-or-nothing: look 1 got its new background, look 2 fell back to the
    // try-on background, and the generation still completed. Both are stored in R2.
    expect($generation->status)->toBe('done');

    $looks = storedLooks($generation);
    expect(collect($looks)->pluck('with_background')->all())->toBe([true, false])
        ->and(Storage::disk('r2')->get($looks[0]['key']))->toBe('BYTES-OF-bg1.png')
        ->and(Storage::disk('r2')->get($looks[1]['key']))->toBe('BYTES-OF-look2.png');
});

it('sanitizes a background-change failure in the logs and never fails the generation', function () {
    config([
        'services.fashn.background.enabled' => true,
        'services.fashn.background.prompts' => ['Dubai Marina'],
    ]);

    Http::fake(function ($request) {
        $url = $request->url();
        if (str_ends_with($url, '/run')) {
            return data_get($request->data(), 'model_name') === 'background-change'
                ? Http::response(['id' => 'bg_1'], 200)
                : Http::response(['id' => 'tryon_1'], 200);
        }
        if (str_contains($url, '/status/bg_')) {
            return Http::response([
                'status' => 'failed',
                'output' => [],
                'error' => ['message' => 'bg failed, ref https://r2.test/secret?X-Amz-Signature=LEAKTOKEN'],
            ], 200);
        }
        if (str_starts_with($url, 'https://cdn/')) {
            return Http::response('LOOK-BYTES', 200);
        }

        return Http::response(['status' => 'completed', 'output' => ['https://cdn/look1.png']], 200);
    });

    $handler = new TestHandler;
    Log::driver()->getLogger()->pushHandler($handler);

    GenerateLookJob::dispatchSync(makeMultiLook(1, withBackground: true));

    $generation = Generation::sole()->refresh();
    $looks = storedLooks($generation);
    expect($generation->status)->toBe('done')
        ->and($looks)->toHaveCount(1)
        ->and($looks[0]['with_background'])->toBeFalse();

    // The background failure was logged, but the tokenized URL never leaked.
    foreach ($handler->getRecords() as $record) {
        $blob = $record->message.' '.json_encode($record->context);
        expect($blob)->not->toContain('LEAKTOKEN')
            ->and($blob)->not->toContain('X-Amz-Signature')
            ->and($blob)->not->toContain('https://r2.test');
    }
});
