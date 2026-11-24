<?php

use App\Jobs\GenerateOutfitJob;
use App\Models\Generation;
use App\Models\Lead;
use App\Models\Product;
use App\Services\Pose\PoseDetector;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Monolog\Handler\TestHandler;
use Tests\Support\FakePoseDetector;

/*
 * GenerateOutfitJob — chains single try-on calls (result N -> person image N+1)
 * over the fal provider, faked end to end (no real engine, no real R2). Sleep is
 * faked so polling waits cost nothing.
 */

beforeEach(function () {
    // Drive the real fal provider (preserve_pose lives there); the suite default is
    // FASHN. fal API + CDN are faked below, so nothing leaves the test.
    config(['services.tryon.driver' => 'fal', 'services.fal.key' => 'test-fal-key']);
    Storage::fake('r2');
    Storage::disk('r2')->buildTemporaryUrlsUsing(
        fn ($path, $expiration, $options = []) => 'https://r2.test/'.$path.'?X-Amz-Token=SECRETTOKEN'
    );
    Sleep::fake();
});

/**
 * Fake the fal queue (submit -> status -> result) plus the CDN download. Each
 * submit gets the next request id; the result image url encodes its step so the
 * chain is observable.
 */
function fakeFalChain(?callable $resultFor = null): void
{
    $resultFor ??= fn (string $id) => Http::response(['image' => ['url' => 'https://fal.media/'.$id.'.png']], 200);
    $submit = 0;

    Http::fake(function ($request) use (&$submit, $resultFor) {
        $url = $request->url();
        if (str_ends_with($url, '/virtual-try-on')) {
            $submit++;

            return Http::response(['request_id' => 'req_'.$submit, 'status' => 'IN_QUEUE'], 200);
        }
        if (preg_match('#/requests/(req_\d+)/status$#', $url)) {
            return Http::response(['status' => 'COMPLETED'], 200);
        }
        if (preg_match('#/requests/(req_\d+)$#', $url, $m)) {
            return $resultFor($m[1]);
        }
        if (str_contains($url, 'fal.media')) {
            return Http::response('FAKE-LOOK-BYTES', 200);
        }

        return Http::response([], 404);
    });
}

function outfitGeneration(): Generation
{
    // The (already Phase-9-processed) client photo the chain starts from.
    Storage::disk('r2')->put('uploads/2026-06-12/original.jpg', 'CLIENT-PHOTO-BYTES');

    $lower = Product::factory()->lowerBody()->create(['image_flat_url' => 'https://garment.test/pants.jpg']);
    $upper = Product::factory()->upperBody()->create(['image_flat_url' => 'https://garment.test/top.jpg']);

    // Stored already in wearing order (the controller orders via OutfitValidator).
    return Generation::factory()->outfit([$lower->id, $upper->id])->create([
        'lead_id' => Lead::factory()->create()->id,
        'client_photo' => 'uploads/2026-06-12/original.jpg',
        'status' => 'queued',
    ]);
}

/** All recorded fal SUBMIT request payloads, in order. */
function falSubmits(): array
{
    return collect(Http::recorded())
        ->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/virtual-try-on'))
        ->map(fn ($pair) => $pair[0]->data())
        ->values()
        ->all();
}

it('is queued (ShouldQueue), tries once, and carries the configured timeout', function () {
    config(['fitting_room.outfit.job_timeout' => 999]);
    $job = new GenerateOutfitJob(outfitGeneration());

    expect(is_subclass_of(GenerateOutfitJob::class, ShouldQueue::class))->toBeTrue()
        ->and($job->tries)->toBe(1)
        ->and($job->timeout)->toBe(999);
});

it('chains two layers in wearing order, with preserve_pose on every call, and stores the final in R2', function () {
    fakeFalChain();
    $generation = outfitGeneration();

    GenerateOutfitJob::dispatchSync($generation);

    $generation->refresh();
    expect($generation->status)->toBe('done')
        ->and($generation->outfit_step)->toBe(2);

    $submits = falSubmits();
    expect($submits)->toHaveCount(2)
        // Order: lower (pants) first, then upper (top) — not the pick order.
        ->and($submits[0]['clothing_image_url'])->toBe('https://garment.test/pants.jpg')
        ->and($submits[1]['clothing_image_url'])->toBe('https://garment.test/top.jpg')
        // preserve_pose rides on EVERY call (added by the provider).
        ->and($submits[0]['preserve_pose'])->toBeTrue()
        ->and($submits[1]['preserve_pose'])->toBeTrue();

    // Step 1 person image = the client photo (signed R2 url).
    expect($submits[0]['person_image_url'])->toContain('uploads/2026-06-12/original.jpg');

    // Step 2 person image = step 1's result, pulled into OUR R2 — NOT a fal CDN url.
    expect($submits[1]['person_image_url'])->toContain('r2.test')
        ->and($submits[1]['person_image_url'])->toContain('outfit-1')
        ->and($submits[1]['person_image_url'])->not->toContain('fal.media');

    // The final look is a signed R2 url, never a fal CDN url.
    $final = $generation->result_urls[0]['url'];
    expect($final)->toContain('r2.test')
        ->and($final)->toContain('outfit-2')
        ->and($final)->not->toContain('fal.media');
});

it('stores the final look without the grey margins the aspect fitter added', function () {
    config(['photo.output.aspect' => '3:4', 'photo.output.pad_color' => '#f0f0f0', 'photo.normalization.driver' => 'gd']);
    $manager = new ImageManager(new Driver);
    $padded = $manager->create(300, 400)->fill('#f0f0f0');
    $padded->place($manager->create(180, 400)->fill('#3a2e28'), 'top-left', 60, 0);
    $bytes = (string) $padded->toPng();

    $submit = 0;
    Http::fake(function ($request) use (&$submit, $bytes) {
        $url = $request->url();
        if (str_ends_with($url, '/virtual-try-on')) {
            $submit++;

            return Http::response(['request_id' => 'req_'.$submit, 'status' => 'IN_QUEUE'], 200);
        }
        if (preg_match('#/requests/(req_\d+)/status$#', $url)) {
            return Http::response(['status' => 'COMPLETED'], 200);
        }
        if (preg_match('#/requests/(req_\d+)$#', $url, $m)) {
            return Http::response(['image' => ['url' => 'https://fal.media/'.$m[1].'.png']], 200);
        }

        return str_contains($url, 'fal.media') ? Http::response($bytes, 200) : Http::response([], 404);
    });
    $generation = outfitGeneration();

    GenerateOutfitJob::dispatchSync($generation);

    $final = $manager->read(Storage::disk('r2')->get($generation->refresh()->result_keys[0]));
    expect([$final->width(), $final->height()])->toBe([180, 400]);
});

it('keeps the final off the fal CDN and deletes the intermediate layer after the chain', function () {
    fakeFalChain();
    $generation = outfitGeneration();

    GenerateOutfitJob::dispatchSync($generation);

    $files = Storage::disk('r2')->allFiles();

    // The transient first layer (outfit-1) is gone; the final (outfit-2) remains,
    // alongside the original client photo.
    expect(collect($files)->filter(fn ($f) => str_contains($f, 'outfit-1')))->toBeEmpty()
        ->and(collect($files)->filter(fn ($f) => str_contains($f, 'outfit-2')))->toHaveCount(1)
        ->and(Storage::disk('r2')->exists('uploads/2026-06-12/original.jpg'))->toBeTrue();
});

it('does NOT re-run Phase 9 preprocessing on the intermediate result', function () {
    fakeFalChain();
    // Bind a pose detector that THROWS if touched. The chain still completes, which
    // proves the job never preprocesses (normalize/pose/crop) the intermediates —
    // Phase 9 ran once at submit, on the original photo only.
    app()->instance(PoseDetector::class, FakePoseDetector::throwing());

    $generation = outfitGeneration();
    GenerateOutfitJob::dispatchSync($generation);

    expect($generation->refresh()->status)->toBe('done');
});

it('fails the whole chain when step 2 fails, accounting for the billed step 1', function () {
    // req_1 completes; req_2's result is rejected → the chain fails at step 2.
    fakeFalChain(resultFor: fn (string $id) => $id === 'req_2'
        ? Http::response(['detail' => 'blocked content'], 422)
        : Http::response(['image' => ['url' => 'https://fal.media/'.$id.'.png']], 200));

    $handler = new TestHandler;
    Log::driver()->getLogger()->pushHandler($handler);

    $generation = outfitGeneration();
    GenerateOutfitJob::dispatchSync($generation);

    $generation->refresh();
    expect($generation->status)->toBe('failed')
        ->and($generation->error)->toContain('blocked content')
        ->and($generation->result_urls)->toBeNull();

    // The intermediate layer from the billed step 1 is cleaned up (no orphan).
    expect(collect(Storage::disk('r2')->allFiles())->filter(fn ($f) => str_contains($f, 'outfit-')))->toBeEmpty();

    // Spend accounting: step 1 was a billed engine call — logged, not pretended free.
    $found = collect($handler->getRecords())->first(
        fn ($r) => str_contains($r->message, 'outfit chain failed')
    );
    expect($found)->not->toBeNull()
        ->and($found->context['failed_step'])->toBe(2)
        ->and($found->context['billed_steps'])->toBe(1)
        ->and($found->context['total_steps'])->toBe(2);
});

it('refuses to run without a captured lead and never calls the engine', function () {
    Http::fake();
    $generation = Generation::factory()->outfit([
        Product::factory()->lowerBody()->create()->id,
        Product::factory()->upperBody()->create()->id,
    ])->create(['lead_id' => null, 'status' => 'queued']);

    GenerateOutfitJob::dispatchSync($generation);

    Http::assertNothingSent();
    expect($generation->refresh()->status)->toBe('failed');
});

it('does not leak the photo key or signed-url token into the failure error or logs', function () {
    // Step 1 result echoes a tokenized URL back in its error.
    fakeFalChain(resultFor: fn (string $id) => Http::response([
        'detail' => 'input rejected, ref https://r2.test/uploads/2026-06-12/original.jpg?X-Amz-Signature=SECRETTOKEN',
    ], 422));

    $handler = new TestHandler;
    Log::driver()->getLogger()->pushHandler($handler);

    $generation = outfitGeneration();
    GenerateOutfitJob::dispatchSync($generation);

    $generation->refresh();
    expect($generation->status)->toBe('failed')
        ->and($generation->error)->toContain('input rejected')
        ->and($generation->error)->not->toContain('SECRETTOKEN')
        ->and($generation->error)->not->toContain('X-Amz-Signature');

    foreach ($handler->getRecords() as $record) {
        $blob = $record->message.' '.json_encode($record->context);
        expect($blob)->not->toContain('SECRETTOKEN')
            ->and($blob)->not->toContain('X-Amz-Signature');
    }
});

it('composes ONE share card set, for the final look only (Этап B1)', function () {
    // Same chain, but the CDN serves a real decodable PNG so the share-card
    // composer can actually read the final look.
    $submit = 0;
    Http::fake(function ($request) use (&$submit) {
        $url = $request->url();
        if (str_ends_with($url, '/virtual-try-on')) {
            $submit++;

            return Http::response(['request_id' => 'req_'.$submit, 'status' => 'IN_QUEUE'], 200);
        }
        if (preg_match('#/requests/(req_\d+)/status$#', $url)) {
            return Http::response(['status' => 'COMPLETED'], 200);
        }
        if (preg_match('#/requests/(req_\d+)$#', $url, $m)) {
            return Http::response(['image' => ['url' => 'https://fal.media/'.$m[1].'.png']], 200);
        }
        if (str_contains($url, 'fal.media')) {
            $png = imagecreatetruecolor(600, 800);
            imagefilledrectangle($png, 0, 0, 600, 800, (int) imagecolorallocate($png, 220, 200, 180));
            ob_start();
            imagepng($png);

            return Http::response((string) ob_get_clean(), 200);
        }

        return Http::response([], 404);
    });

    $generation = outfitGeneration();
    GenerateOutfitJob::dispatchSync($generation);

    $generation->refresh();
    $keys = $generation->share_keys;

    expect($generation->status)->toBe('done')
        ->and($keys)->toHaveKeys(['feed', 'story']);

    // Exactly one card per format exists — none for the intermediate layer.
    $cards = collect(Storage::disk('r2')->allFiles())
        ->filter(fn (string $file) => str_contains($file, '-share-'));
    expect($cards)->toHaveCount(2);
});
