<?php

use App\Services\Photo\DefaultPhotoValidator;
use App\Services\Photo\PhotoValidator;
use App\Services\Photo\ValidationFailureReason;
use App\Services\Pose\BoundingBox;
use App\Services\Pose\Landmark;
use App\Services\Pose\Person;
use App\Services\Pose\PoseResult;

/*
 * Photo suitability gate (9.2). Runs against hand-built PoseResult fixtures — no
 * pose service, no FASHN. The same waist-up photo passes for a top but fails for
 * a dress: requirements depend on the garment category.
 */

function photoValidator(): PhotoValidator
{
    return app(DefaultPhotoValidator::class);
}

function landmark(string $name, float $confidence, float $x = 0.0, float $y = 0.0): Landmark
{
    return new Landmark($name, $x, $y, 0.0, 0.0, 0.0, $confidence);
}

/**
 * Build a single-person PoseResult from a list of landmarks.
 *
 * @param  array<Landmark>  $landmarks
 */
function poseWith(array $landmarks, int $personCount = 1, int $width = 1000, int $height = 1500): PoseResult
{
    $keyed = [];
    foreach ($landmarks as $lm) {
        $keyed[$lm->name] = $lm;
    }

    $person = $personCount >= 1
        ? new Person(new BoundingBox(0.0, 0.0, (float) $width, (float) $height), $keyed)
        : null;

    return new PoseResult($personCount, $width, $height, $person, 0.5);
}

/**
 * A confident, frontal torso (both shoulders separated + both hips). Callers add
 * legs/face as needed.
 *
 * @return array<Landmark>
 */
function frontalTorso(): array
{
    return [
        landmark('nose', 0.99, x: 500, y: 120),
        landmark('left_shoulder', 0.98, x: 640, y: 350),   // person's left = image right
        landmark('right_shoulder', 0.98, x: 360, y: 350),  // separation 280/1000 = 0.28
        landmark('left_hip', 0.95, x: 600, y: 800),
        landmark('right_hip', 0.95, x: 400, y: 800),
    ];
}

it('passes a full-body, frontal, single-person photo for a dress', function () {
    $pose = poseWith([
        ...frontalTorso(),
        landmark('left_ankle', 0.93, x: 590, y: 1400),
        landmark('right_ankle', 0.92, x: 410, y: 1400),
    ]);

    $result = photoValidator()->validate($pose, 'dresses');

    expect($result->passed)->toBeTrue()
        ->and($result->validated)->toBeTrue()
        ->and($result->reason)->toBeNull();
});

it('rejects a waist-up photo for a dress (NOT_FULL_BODY)', function () {
    // Ankles extrapolated off-frame: present but ~0.02 confidence.
    $pose = poseWith([
        ...frontalTorso(),
        landmark('left_ankle', 0.02, x: 590, y: 1800),
        landmark('right_ankle', 0.02, x: 410, y: 1800),
    ]);

    $result = photoValidator()->validate($pose, 'dresses');

    expect($result->passed)->toBeFalse()
        ->and($result->reason)->toBe(ValidationFailureReason::NotFullBody);
});

it('passes a full-body dress photo whose crossed leg leaves the ankle just under the bar but the heel above it', function () {
    // Real prod pose (black tights, legs crossed): right_ankle 0.49 < 0.5 used
    // to reject a plainly full-length photo as NOT_FULL_BODY.
    $pose = poseWith([
        ...frontalTorso(),
        landmark('left_ankle', 0.88, x: 590, y: 1300),
        landmark('right_ankle', 0.49, x: 410, y: 1210),
        landmark('right_heel', 0.57, x: 410, y: 1230),
        landmark('right_foot_index', 0.55, x: 420, y: 1300),
    ]);

    expect(photoValidator()->validate($pose, 'dresses')->passed)->toBeTrue();
});

it('still rejects a dress photo when one foot has no confident ankle, heel or toe', function () {
    $pose = poseWith([
        ...frontalTorso(),
        landmark('left_ankle', 0.93, x: 590, y: 1400),
        landmark('right_ankle', 0.04, x: 410, y: 1800),
        landmark('right_heel', 0.03, x: 410, y: 1820),
        landmark('right_foot_index', 0.03, x: 420, y: 1850),
    ]);

    expect(photoValidator()->validate($pose, 'dresses')->reason)
        ->toBe(ValidationFailureReason::NotFullBody);
});

it('accepts the SAME waist-up photo for a top (legs not required)', function () {
    $pose = poseWith([
        ...frontalTorso(),
        landmark('left_ankle', 0.02, x: 590, y: 1800),
        landmark('right_ankle', 0.02, x: 410, y: 1800),
    ]);

    $result = photoValidator()->validate($pose, 'upper_body');

    expect($result->passed)->toBeTrue()
        ->and($result->reason)->toBeNull();
});

it('rejects lower_body (trousers) waist-up just like a dress (NOT_FULL_BODY)', function () {
    $pose = poseWith([
        ...frontalTorso(),
        landmark('left_ankle', 0.02),
        landmark('right_ankle', 0.02),
    ]);

    expect(photoValidator()->validate($pose, 'lower_body')->reason)
        ->toBe(ValidationFailureReason::NotFullBody);
});

it('rejects a photo with no person (NO_PERSON)', function () {
    $pose = poseWith([], personCount: 0, width: 640, height: 480);

    $result = photoValidator()->validate($pose, 'upper_body');

    expect($result->passed)->toBeFalse()
        ->and($result->reason)->toBe(ValidationFailureReason::NoPerson);
});

it('rejects a photo with multiple people (MULTIPLE_PEOPLE)', function () {
    $pose = poseWith(frontalTorso(), personCount: 2);

    $result = photoValidator()->validate($pose, 'upper_body');

    expect($result->passed)->toBeFalse()
        ->and($result->reason)->toBe(ValidationFailureReason::MultiplePeople);
});

it('rejects a profile / side pose (NOT_FRONTAL)', function () {
    // The far shoulder and hip are occluded -> low confidence.
    $pose = poseWith([
        landmark('nose', 0.97, x: 500, y: 120),
        landmark('left_shoulder', 0.98, x: 520, y: 350),
        landmark('right_shoulder', 0.12, x: 500, y: 350),
        landmark('left_hip', 0.95, x: 510, y: 800),
        landmark('right_hip', 0.10, x: 500, y: 800),
        landmark('left_ankle', 0.9, x: 505, y: 1400),
        landmark('right_ankle', 0.1, x: 500, y: 1400),
    ]);

    $result = photoValidator()->validate($pose, 'dresses');

    expect($result->passed)->toBeFalse()
        ->and($result->reason)->toBe(ValidationFailureReason::NotFrontal);
});

it('rejects a face-only selfie (FACE_ONLY)', function () {
    $pose = poseWith([
        landmark('nose', 0.99, x: 500, y: 250),
        landmark('left_eye', 0.99, x: 540, y: 230),
        landmark('right_eye', 0.99, x: 460, y: 230),
        // Body not visible.
        landmark('left_shoulder', 0.08),
        landmark('right_shoulder', 0.07),
        landmark('left_hip', 0.03),
        landmark('right_hip', 0.03),
    ]);

    $result = photoValidator()->validate($pose, 'upper_body');

    expect($result->passed)->toBeFalse()
        ->and($result->reason)->toBe(ValidationFailureReason::FaceOnly);
});

it('carries a machine reason AND a resolvable translatable message for every failure', function () {
    foreach (ValidationFailureReason::cases() as $reason) {
        $key = $reason->messageKey();

        expect($key)->toStartWith('photo_validation.')
            // The translation exists (resolves to something other than the key).
            ->and(__($key))->not->toBe($key);
    }
});

it('passes through an empty pose result without crashing (fail-open)', function () {
    // The NullPoseDetector signature: no person, no dimensions.
    $pose = new PoseResult(0, 0, 0, null, 0.5);

    $result = photoValidator()->validate($pose, 'dresses');

    expect($result->passed)->toBeTrue()
        ->and($result->validated)->toBeFalse()
        ->and($result->reason)->toBeNull();
});

it('treats a real no-person result (with dimensions) as NO_PERSON, not pass-through', function () {
    // Distinct from the empty/stub case: the service looked and found nobody.
    $pose = new PoseResult(0, 640, 480, null, 0.5);

    $result = photoValidator()->validate($pose, 'dresses');

    expect($result->passed)->toBeFalse()
        ->and($result->reason)->toBe(ValidationFailureReason::NoPerson);
});
