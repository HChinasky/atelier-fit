<?php

use App\Services\Pose\MediaPipePoseDetector;
use App\Services\Pose\PoseDetectionException;
use App\Services\Pose\PoseResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/*
 * MediaPipe pose detector — request shaping, response parsing, and failure
 * handling. The pose service is ALWAYS faked; no real call leaves the test and
 * no photo bytes are ever logged.
 */

beforeEach(function () {
    config([
        'services.pose.url' => 'http://pose.test',
        'services.pose.token' => 'test-token',
        'services.pose.timeout' => 15,
    ]);
});

function poseDetector(): MediaPipePoseDetector
{
    return app(MediaPipePoseDetector::class);
}

function successBody(int $personCount = 1): array
{
    $main = $personCount >= 1 ? [
        'bounding_box' => ['x' => 209.09, 'y' => 89.54, 'width' => 163.55, 'height' => 760.6],
        'landmarks' => [
            ['name' => 'nose', 'x' => 298.32, 'y' => 104.52, 'x_norm' => 0.49719, 'y_norm' => 0.11613, 'z' => -0.6092, 'confidence' => 0.9999],
            ['name' => 'left_ankle', 'x' => 792.13, 'y' => 850.0, 'x_norm' => 0.9, 'y_norm' => 0.94, 'z' => 0.1, 'confidence' => 0.9331],
        ],
    ] : null;

    return [
        'person_count' => $personCount,
        'image_dimensions' => ['width' => 600, 'height' => 900],
        'main_person' => $main,
        'bbox_confidence_threshold' => 0.5,
        'model' => 'mediapipe_pose_landmarker_full_v1',
    ];
}

it('sends the photo as a multipart upload with the X-API-Token header', function () {
    Http::fake(['*/pose' => Http::response(successBody(), 200)]);

    poseDetector()->detect('RAW-IMAGE-BYTES', 'me.jpg');

    Http::assertSent(function ($request) {
        return str_ends_with($request->url(), '/pose')
            && $request->isMultipart()
            && $request->hasHeader('X-API-Token', 'test-token')
            // The image is sent under the "image" field.
            && collect($request->data())->contains(fn ($part) => ($part['name'] ?? null) === 'image');
    });
});

it('omits the token header when none is configured', function () {
    config(['services.pose.token' => null]);
    Http::fake(['*/pose' => Http::response(successBody(), 200)]);

    poseDetector()->detect('RAW-IMAGE-BYTES');

    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/pose')
        && ! $request->hasHeader('X-API-Token'));
});

it('parses a successful response into a typed PoseResult', function () {
    Http::fake(['*/pose' => Http::response(successBody(), 200)]);

    $result = poseDetector()->detect('RAW-IMAGE-BYTES');

    expect($result)->toBeInstanceOf(PoseResult::class)
        ->and($result->personCount)->toBe(1)
        ->and($result->imageWidth)->toBe(600)
        ->and($result->imageHeight)->toBe(900)
        ->and($result->bboxConfidenceThreshold)->toBe(0.5)
        ->and($result->hasMainPerson())->toBeTrue();

    $person = $result->mainPerson;
    expect($person->boundingBox->x)->toBe(209.09)
        ->and($person->boundingBox->width)->toBe(163.55)
        ->and($person->boundingBox->height)->toBe(760.6)
        // Landmarks are keyed by name for direct lookup by 9.2/9.4.
        ->and($person->landmark('nose')->confidence)->toBe(0.9999)
        // confidence honestly distinguishes visible (high) vs extrapolated points.
        ->and($person->landmark('left_ankle')->confidence)->toBe(0.9331)
        ->and($person->landmark('missing'))->toBeNull();
});

it('handles a no-person response (person_count 0, main_person null) without error', function () {
    Http::fake(['*/pose' => Http::response(successBody(0), 200)]);

    $result = poseDetector()->detect('RAW-IMAGE-BYTES');

    expect($result->personCount)->toBe(0)
        ->and($result->mainPerson)->toBeNull()
        ->and($result->hasMainPerson())->toBeFalse()
        // Dimensions are still reported.
        ->and($result->imageWidth)->toBe(600);
});

it('throws PoseDetectionException on a 5xx response', function () {
    Http::fake(['*/pose' => Http::response('upstream error', 500)]);

    expect(fn () => poseDetector()->detect('RAW-IMAGE-BYTES'))
        ->toThrow(PoseDetectionException::class);
});

it('throws PoseDetectionException on a transport failure / timeout', function () {
    Http::fake(fn () => throw new ConnectionException('Connection timed out'));

    expect(fn () => poseDetector()->detect('RAW-IMAGE-BYTES'))
        ->toThrow(PoseDetectionException::class);
});

it('throws PoseDetectionException on an unexpected response shape', function () {
    Http::fake(['*/pose' => Http::response(['unexpected' => true], 200)]);

    expect(fn () => poseDetector()->detect('RAW-IMAGE-BYTES'))
        ->toThrow(PoseDetectionException::class);
});

it('throws when image_dimensions is missing (no silent 0)', function () {
    $body = successBody();
    unset($body['image_dimensions']);
    Http::fake(['*/pose' => Http::response($body, 200)]);

    expect(fn () => poseDetector()->detect('RAW-IMAGE-BYTES'))
        ->toThrow(PoseDetectionException::class);
});

it('throws when image_dimensions has a non-positive dimension', function () {
    $body = successBody();
    $body['image_dimensions']['width'] = 0;
    Http::fake(['*/pose' => Http::response($body, 200)]);

    expect(fn () => poseDetector()->detect('RAW-IMAGE-BYTES'))
        ->toThrow(PoseDetectionException::class);
});

it('throws when bbox_confidence_threshold is missing (no silent 0)', function () {
    $body = successBody();
    unset($body['bbox_confidence_threshold']);
    Http::fake(['*/pose' => Http::response($body, 200)]);

    expect(fn () => poseDetector()->detect('RAW-IMAGE-BYTES'))
        ->toThrow(PoseDetectionException::class);
});

it('throws when a detected person has no bounding_box', function () {
    $body = successBody();
    unset($body['main_person']['bounding_box']);
    Http::fake(['*/pose' => Http::response($body, 200)]);

    expect(fn () => poseDetector()->detect('RAW-IMAGE-BYTES'))
        ->toThrow(PoseDetectionException::class);
});

it('throws when a detected person has no landmarks', function () {
    $body = successBody();
    $body['main_person']['landmarks'] = [];
    Http::fake(['*/pose' => Http::response($body, 200)]);

    expect(fn () => poseDetector()->detect('RAW-IMAGE-BYTES'))
        ->toThrow(PoseDetectionException::class);
});

it('throws when main_person is absent though person_count >= 1', function () {
    $body = successBody();
    $body['main_person'] = null;
    Http::fake(['*/pose' => Http::response($body, 200)]);

    expect(fn () => poseDetector()->detect('RAW-IMAGE-BYTES'))
        ->toThrow(PoseDetectionException::class);
});

it('keeps the photo out of the message and logs on a contract violation', function () {
    $marker = 'SECRET-PHOTO-BYTES-MARKER-67890';

    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = $message->message.' '.json_encode($message->context);
    });

    // Valid HTTP 200 but a body that violates the contract.
    Http::fake(['*/pose' => Http::response(['person_count' => 1], 200)]);

    try {
        poseDetector()->detect($marker, 'me.jpg');
    } catch (PoseDetectionException $e) {
        expect($e->getMessage())->not->toContain($marker);
    }

    expect($logged)->not->toBeEmpty();
    foreach ($logged as $line) {
        expect($line)->not->toContain($marker);
    }
});

it('never writes the photo contents to the logs', function () {
    $marker = 'SECRET-PHOTO-BYTES-MARKER-12345';

    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = $message->message.' '.json_encode($message->context);
    });

    // Force the failure path (the one that logs) and pass the marker as the photo.
    Http::fake(['*/pose' => Http::response('error', 500)]);

    try {
        poseDetector()->detect($marker, 'me.jpg');
    } catch (PoseDetectionException) {
        // expected
    }

    expect($logged)->not->toBeEmpty();
    foreach ($logged as $line) {
        expect($line)->not->toContain($marker);
    }
});
