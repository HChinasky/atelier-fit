<?php

use App\Jobs\AnalyzeColorProfile;
use App\Models\ColorProfile;
use App\Models\ColorProfileImage;
use App\Services\Color\ColorAnalysisClient;
use App\Services\Color\ColorAnalysisUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
 * AnalyzeColorProfile — the job that asks the engine and writes down the answer.
 *
 * The distinction the tests keep hammering: a photo the engine REFUSED is a
 * normal result that must never be retried, while a service that was DOWN is our
 * problem and is the only thing that should be.
 */

beforeEach(function () {
    Storage::fake('r2');
    config([
        'services.pose.url' => 'http://pose.test:8000',
        'services.pose.token' => '',
        'privacy.photo_hard_delete_hours' => 60,
    ]);
});

/** A profile with its source photo actually present in (fake) storage. */
function profileWithPhoto(array $state = []): ColorProfile
{
    $profile = ColorProfile::factory()->create($state);
    $image = ColorProfileImage::factory()->for($profile, 'profile')->create();
    Storage::disk($image->disk)->put($image->path, 'PHOTO-BYTES');

    return $profile;
}

/** The shape pose-service answers with. */
function analysisBody(string $status = 'ok', ?string $reason = null, array $images = []): array
{
    return [
        'result' => [
            'status' => $status,
            'reject_reason' => $reason,
            'source_color_space' => 'sRGB (assumed)',
            'samples' => ['skin' => ['L' => 70.1, 'a' => 12.4, 'b' => 16.8, 'pixels' => 4200]],
            'white_balance' => [
                'method' => 'sclera',
                'single_eye' => false,
                'correction_strength' => 0.0184,
                'estimated_cct' => 4180.0,
                'target_calibrated' => false,
            ],
            'axes' => ['temperature' => -0.41, 'depth' => 0.68, 'chroma' => 0.12],
            'contrast' => [
                'value' => 0.63,
                'hair_skin' => 45.5,
                'eye_skin' => 28.8,
                'iris_sclera' => 43.4,
            ],
            'seasons' => [
                ['slug' => 'deep_winter', 'score' => 0.2731],
                ['slug' => 'deep_autumn', 'score' => 0.1904],
            ],
            'classifier' => [
                'version' => 'draft-1',
                'draft' => true,
                'low_confidence' => false,
            ],
            'confidence' => 0.62,
            'algo_version' => '0.1.0',
        ],
        'debug_images' => $images,
    ];
}

/** The same answer from an engine that could not place a season. */
function unplacedBody(): array
{
    $body = analysisBody();
    $body['result']['seasons'] = [];
    $body['result']['classifier'] = null;
    $body['result']['confidence'] = null;

    return $body;
}

it('records what the engine measured', function () {
    Http::fake(['*' => Http::response(analysisBody())]);
    $profile = profileWithPhoto();

    (new AnalyzeColorProfile($profile))->handle(app(ColorAnalysisClient::class));

    $profile->refresh();
    expect($profile->status)->toBe(ColorProfile::STATUS_OK)
        ->and($profile->reject_reason)->toBeNull()
        ->and($profile->samples['skin']['L'])->toBe(70.1)
        ->and($profile->white_balance['method'])->toBe('sclera')
        ->and($profile->algo_version)->toBe('0.1.0')
        ->and($profile->target_calibrated)->toBeFalse()
        ->and($profile->error)->toBeNull();
});

it('stores a rejected zone with the numbers behind the rejection', function () {
    /* The engine reports a side that failed its checks WITH its measurement:
     * "darker_than_skin" does not say how much darker, and a threshold nobody
     * can see the margin on cannot be reviewed. Nothing here reshapes the
     * contract, and this is what pins that - the numbers and the verdict must
     * both survive the round trip into the row. */
    $body = analysisBody();
    $body['result']['samples']['sclera'] = null;
    $body['result']['samples']['sclera_parts'] = [
        'left' => ['L' => 66.2, 'a' => 0.4, 'b' => 2.9, 'C' => 2.9, 'h' => 82.1, 'pixels' => 410],
        'right' => ['L' => 64.8, 'a' => 0.6, 'b' => 3.1, 'C' => 3.2, 'h' => 79.0, 'pixels' => 380],
    ];
    $body['result']['zones'] = [
        'sclera' => ['usable' => false, 'reason' => 'darker_than_skin'],
        'sclera_left' => ['usable' => false, 'reason' => 'darker_than_skin'],
        'sclera_right' => ['usable' => false, 'reason' => 'darker_than_skin'],
    ];
    Http::fake(['*' => Http::response($body)]);
    $profile = profileWithPhoto();

    (new AnalyzeColorProfile($profile))->handle(app(ColorAnalysisClient::class));

    $profile->refresh();
    expect($profile->samples['sclera_parts']['left']['L'])->toBe(66.2)
        ->and($profile->samples['sclera_parts']['right']['L'])->toBe(64.8)
        // The pooled zone genuinely has nothing: it is never computed from
        // eyes that failed, so there is no number to keep.
        ->and($profile->samples['sclera'])->toBeNull()
        ->and($profile->zones['sclera_left']['usable'])->toBeFalse()
        ->and($profile->zones['sclera_left']['reason'])->toBe('darker_than_skin');
});

it('records the season, the runner-up and which classifier decided them', function () {
    /* All three. A slug on its own is a claim with no date on it: the engine's
     * anchor points live in a versioned data file, and two profiles reading
     * 'deep_winter' six months apart may have been decided by different
     * numbers with nothing in the row to show it. */
    Http::fake(['*' => Http::response(analysisBody())]);
    $profile = profileWithPhoto();

    (new AnalyzeColorProfile($profile))->handle(app(ColorAnalysisClient::class));

    $profile->refresh();
    expect($profile->season)->toBe('deep_winter')
        ->and($profile->rankedSeasons())->toBe(['deep_winter' => 0.2731, 'deep_autumn' => 0.1904])
        ->and($profile->classifier['version'])->toBe('draft-1')
        ->and($profile->isDraftClassification())->toBeTrue()
        ->and($profile->hasLowConfidence())->toBeFalse()
        ->and($profile->confidence)->toBe(0.62)
        ->and($profile->axes['depth'])->toBe(0.68)
        ->and($profile->contrast['iris_sclera'])->toBe(43.4);
});

it('leaves the season null when the engine could not place one', function () {
    /* A measured photo with no season is a real outcome: an axis the engine
     * could not compute at all is better reported as no season than as a season
     * derived from three defaults. */
    Http::fake(['*' => Http::response(unplacedBody())]);
    $profile = profileWithPhoto();

    (new AnalyzeColorProfile($profile))->handle(app(ColorAnalysisClient::class));

    $profile->refresh();
    expect($profile->status)->toBe(ColorProfile::STATUS_OK)
        ->and($profile->season)->toBeNull()
        ->and($profile->classifier)->toBeNull();
});

it('treats an unclassified profile as a draft rather than as approved', function () {
    /* Absence of the flag must not read as "checked by a stylist". */
    $profile = ColorProfile::factory()->create(['classifier' => null]);

    expect($profile->isDraftClassification())->toBeTrue();
});

it('sends the photo untouched, with debug on', function () {
    /* Debug because this is the admin instrument and the pictures are the point;
     * untouched because the engine does its own colour management and anything
     * done to the pixels first is measurement error. */
    Http::fake(['*' => Http::response(analysisBody())]);
    $profile = profileWithPhoto();

    (new AnalyzeColorProfile($profile))->handle(app(ColorAnalysisClient::class));

    Http::assertSent(function ($request) {
        $body = (string) $request->body();

        return str_contains($request->url(), '/color-analysis')
            && str_contains($body, 'PHOTO-BYTES')
            && str_contains($body, 'true');
    });
});

it('passes the quiz along when there is one', function () {
    Http::fake(['*' => Http::response(analysisBody())]);
    $profile = profileWithPhoto(['quiz' => ['metal' => 'gold', 'veins' => 'blue']]);

    (new AnalyzeColorProfile($profile))->handle(app(ColorAnalysisClient::class));

    Http::assertSent(fn ($request) => str_contains((string) $request->body(), '"metal":"gold"'));
});

it('stores the debug views beside the photo, on the same clock', function () {
    $png = base64_encode('PNG-BYTES');
    Http::fake([
        '*' => Http::response(analysisBody(images: ['overlay' => $png, 'crop_forehead' => $png])),
    ]);
    $profile = profileWithPhoto();
    $source = $profile->images()->first();

    (new AnalyzeColorProfile($profile))->handle(app(ColorAnalysisClient::class));

    $overlay = $profile->images()->where('kind', 'overlay')->first();
    expect($overlay)->not->toBeNull()
        ->and(dirname($overlay->path))->toBe(dirname($source->path))
        ->and($overlay->expires_at->timestamp)->toBe($source->expires_at->timestamp);
    Storage::disk($overlay->disk)->assertExists($overlay->path);
    expect($profile->images()->count())->toBe(3);  // source + two views
});

it('treats a refused photo as a finished result, not a failure', function () {
    Http::fake(['*' => Http::response(analysisBody('rejected', 'no_face'))]);
    $profile = profileWithPhoto();

    (new AnalyzeColorProfile($profile))->handle(app(ColorAnalysisClient::class));

    $profile->refresh();
    expect($profile->status)->toBe(ColorProfile::STATUS_REJECTED)
        ->and($profile->reject_reason)->toBe('no_face')
        ->and($profile->error)->toBeNull();
});

it('does not retry a refused photo', function () {
    /* The same bytes get the same answer every time. Retrying only burns a
     * worker and keeps the page spinning. */
    Http::fake(['*' => Http::response(analysisBody('rejected', 'low_light'))]);
    $profile = profileWithPhoto();

    (new AnalyzeColorProfile($profile))->handle(app(ColorAnalysisClient::class));

    Http::assertSentCount(1);
    expect($profile->refresh()->status)->toBe(ColorProfile::STATUS_REJECTED);
});

it('fails without retrying when the service refuses the request', function () {
    /* A 4xx means the request itself is wrong. Sending it again changes nothing. */
    Http::fake(['*' => Http::response(['detail' => 'Malformed quiz'], 400)]);
    $profile = profileWithPhoto();

    $job = new AnalyzeColorProfile($profile);
    $job->handle(app(ColorAnalysisClient::class));

    Http::assertSentCount(1);
    $profile->refresh();
    expect($profile->status)->toBe(ColorProfile::STATUS_FAILED)
        ->and($profile->error)->toContain('400')
        ->and($profile->reject_reason)->toBeNull();
});

it('fails without retrying when the service has no colour engine', function () {
    /* A 503 from THIS endpoint means the package, the models or the switch are
     * missing on that deployment. None of that changes in the next sixty
     * seconds, so it is a failure and not something to try again. */
    Http::fake(['*' => Http::response(['detail' => 'Colour analysis is switched off on this service.'], 503)]);
    $profile = profileWithPhoto();

    (new AnalyzeColorProfile($profile))->handle(app(ColorAnalysisClient::class));

    Http::assertSentCount(1);
    $profile->refresh();
    expect($profile->status)->toBe(ColorProfile::STATUS_FAILED)
        ->and($profile->error)->toContain('switched off')
        ->and($profile->reject_reason)->toBeNull();
});

it('passes the reason for a 503 through to the page', function () {
    /* So an operator learns what to fix from the admin page, without going to
     * the service's logs. */
    Http::fake(['*' => Http::response(['detail' => 'color-engine models not found: /srv/models'], 503)]);
    $profile = profileWithPhoto();

    (new AnalyzeColorProfile($profile))->handle(app(ColorAnalysisClient::class));

    expect($profile->refresh()->error)->toContain('models not found');
});

it('fails when the photo is gone', function () {
    /* Swept by the retention purge while the job sat in the queue. Nothing to
     * analyse, nothing to retry — say so rather than spinning. */
    $profile = ColorProfile::factory()->create();

    (new AnalyzeColorProfile($profile))->handle(app(ColorAnalysisClient::class));

    expect($profile->refresh()->status)->toBe(ColorProfile::STATUS_FAILED)
        ->and($profile->error)->toContain('no longer available');
    Http::assertNothingSent();
});

it('retries a service that was down, and only that', function () {
    /* The transient case is handed to the queue rather than decided here: it
     * already knows how many attempts are left and how long to wait. Reaching
     * the caller as an exception is what makes it retry. */
    Http::fake(['*' => Http::response('', 500)]);
    $profile = profileWithPhoto();
    $job = new AnalyzeColorProfile($profile);

    expect(fn () => $job->handle(app(ColorAnalysisClient::class)))
        ->toThrow(ColorAnalysisUnavailable::class);
    expect($job->tries)->toBe(3);
});

it('retries a transport error too', function () {
    Http::fake(fn () => throw new ConnectionException('Connection refused'));
    $profile = profileWithPhoto();
    $job = new AnalyzeColorProfile($profile);

    expect(fn () => $job->handle(app(ColorAnalysisClient::class)))
        ->toThrow(ColorAnalysisUnavailable::class);
});

it('gives up once the attempts are spent, so the page stops spinning', function () {
    $profile = profileWithPhoto();

    (new AnalyzeColorProfile($profile))->failed(
        new ColorAnalysisUnavailable('Colour analysis service returned 500')
    );

    $profile->refresh();
    expect($profile->status)->toBe(ColorProfile::STATUS_FAILED)
        ->and($profile->error)->toContain('500')
        /* Collapsing failure into rejection would tell an admin to retake a
         * photo because a service was down. */
        ->and($profile->reject_reason)->toBeNull();
});

it('rides its own queue so it cannot hold up try-on work', function () {
    Queue::fake();
    $profile = ColorProfile::factory()->create();

    AnalyzeColorProfile::dispatch($profile)
        ->onConnection((string) config('color_analysis.queue_connection'))
        ->onQueue((string) config('color_analysis.queue'));

    Queue::assertPushedOn('color', AnalyzeColorProfile::class);
});

it('asks for no debug views when the caller says so, and stores none', function () {
    /* The member's page never shows the pictures, so none are asked for and
     * none land in storage: nothing to leak, nothing to purge. */
    Http::fake(['*' => Http::response(analysisBody())]);
    $profile = profileWithPhoto();

    (new AnalyzeColorProfile($profile, debug: false))->handle(app(ColorAnalysisClient::class));

    Http::assertSent(fn ($request) => str_contains((string) $request->body(), 'false')
        && ! str_contains((string) $request->body(), 'true'));
    expect($profile->refresh()->status)->toBe(ColorProfile::STATUS_OK)
        ->and($profile->images()->where('kind', '!=', 'source')->count())->toBe(0);
});

// --- the member's pinned season --------------------------------------------

it('pins the season on the member when their own photo places one', function () {
    Http::fake(['*' => Http::response(analysisBody())]);
    $profile = profileWithPhoto(['consented_at' => now()]);

    (new AnalyzeColorProfile($profile, false))->handle(app(ColorAnalysisClient::class));

    expect($profile->user->fresh()->color_profile_id)->toBe($profile->id);
});

it('keeps the pinned season when a retake is refused or places none', function (array $body) {
    $pinned = profileWithPhoto(ColorProfile::factory()->classified()->raw(['consented_at' => now()]));
    $pinned->user->forceFill(['color_profile_id' => $pinned->id])->save();

    Http::fake(['*' => Http::response($body)]);
    $retake = profileWithPhoto(['user_id' => $pinned->user_id, 'consented_at' => now()]);

    (new AnalyzeColorProfile($retake, false))->handle(app(ColorAnalysisClient::class));

    expect($pinned->user->fresh()->color_profile_id)->toBe($pinned->id);
})->with([
    'refused' => fn () => analysisBody('rejected', 'low_light'),
    'unplaced' => fn () => unplacedBody(),
]);

it('pins a member\'s own analysis that carries no consent stamp, consent being switched off', function () {
    Http::fake(['*' => Http::response(analysisBody())]);
    $profile = profileWithPhoto(['consented_at' => null]);

    (new AnalyzeColorProfile($profile, debug: false))->handle(app(ColorAnalysisClient::class));

    expect($profile->user->fresh()->color_profile_id)->toBe($profile->id);
});

it('never pins an instrument upload, which may be somebody else\'s face', function () {
    Http::fake(['*' => Http::response(analysisBody())]);
    $profile = profileWithPhoto(['consented_at' => null, 'person_ref' => 'model-7']);

    (new AnalyzeColorProfile($profile))->handle(app(ColorAnalysisClient::class));

    expect($profile->user->fresh()->color_profile_id)->toBeNull();
});
