<?php

use App\Services\Photo\NullImageEnhancer;
use App\Services\Photo\PhotoEnhancer;
use App\Services\Photo\PhotoPreprocessor;
use App\Services\Photo\PoseServiceImageEnhancer;
use App\Services\Photo\ValidationFailureReason;
use App\Services\Pose\PoseDetector;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeImageEnhancer;
use Tests\Support\FakePoseDetector;
use Tests\Support\PoseFixtures;

/*
 * Preprocessing pipeline (9.5): orientation → pose → validation → enhancement →
 * crop, with the fail-open / fail-closed degradation policy. Pose is always a
 * FakePoseDetector (no live service); normalizer/validator/cropper are the real
 * components. The enhancer defaults to the Null enhancer (both steps off in the
 * suite); tests that exercise 9.5 bind a FakeImageEnhancer explicitly.
 */

function preprocessorWith(PoseDetector $detector): PhotoPreprocessor
{
    app()->instance(PoseDetector::class, $detector);

    return app(PhotoPreprocessor::class);
}

function preprocessorWithEnhancer(PoseDetector $detector, PhotoEnhancer $enhancer): PhotoPreprocessor
{
    app()->instance(PoseDetector::class, $detector);
    app()->instance(PhotoEnhancer::class, $enhancer);

    return app(PhotoPreprocessor::class);
}

// These tests assert the CROP geometry, so the 9.6 output aspect-fit is off here
// (it reframes the crop). The fit is covered on its own in PhotoAspectFitterTest
// and in the dedicated fit test below.
beforeEach(fn () => config(['photo.output.aspect' => null]));

it('processes a suitable full-body dress photo: normalized, cropped, smaller', function () {
    $original = PoseFixtures::jpeg(1000, 1500);
    $pre = preprocessorWith(FakePoseDetector::returning(PoseFixtures::fullBody(1000, 1500)));

    $result = $pre->process($original, 'dresses');

    expect($result->isProcessed())->toBeTrue();

    // The processed photo is a real image, cropped to the person (smaller than the
    // 1000-wide source) — proof the pipeline actually ran.
    [$w, $h] = getimagesizefromstring($result->contents);
    expect($w)->toBeLessThan(1000)->and($w)->toBeGreaterThan(0)->and($h)->toBeGreaterThan(0);
});

it('rejects a waist-up photo for a dress (NOT_FULL_BODY) before any generation', function () {
    $pre = preprocessorWith(FakePoseDetector::returning(PoseFixtures::waistUp(1000, 1500)));

    $result = $pre->process(PoseFixtures::jpeg(1000, 1500), 'dresses');

    expect($result->isRejected())->toBeTrue()
        ->and($result->reason)->toBe(ValidationFailureReason::NotFullBody);
});

it('accepts the SAME waist-up photo for a top (catalog category drives the rule)', function () {
    // Identical pose; only the catalog category differs. If the controller passed
    // the FASHN-mapped category (tops/one-pieces) the full-body rule would silently
    // misfire — here the catalog value (upper_body) is what flows through.
    $pre = preprocessorWith(FakePoseDetector::returning(PoseFixtures::waistUp(1000, 1500)));

    $result = $pre->process(PoseFixtures::jpeg(1000, 1500), 'upper_body');

    expect($result->isProcessed())->toBeTrue();
});

it('skips preprocessing when the pose service is unavailable and the policy is fail-open', function () {
    config(['services.pose.failure_mode' => 'fail_open']);
    $pre = preprocessorWith(FakePoseDetector::throwing());

    $result = $pre->process(PoseFixtures::jpeg(800, 1000), 'dresses');

    expect($result->isSkipped())->toBeTrue();
});

it('blocks generation when the pose service is unavailable and the policy is fail-closed', function () {
    config(['services.pose.failure_mode' => 'fail_closed']);
    $pre = preprocessorWith(FakePoseDetector::throwing());

    $result = $pre->process(PoseFixtures::jpeg(800, 1000), 'dresses');

    expect($result->isUnavailable())->toBeTrue();
});

it('passes through (processed, not rejected) when pose data is empty', function () {
    // The Null-detector signature: no judgement possible -> the photo is let
    // through, normalized but not cropped. NOT a rejection.
    $pre = preprocessorWith(FakePoseDetector::returning(PoseFixtures::empty()));

    $result = $pre->process(PoseFixtures::jpeg(900, 1200), 'dresses');

    expect($result->isProcessed())->toBeTrue();
    [$w, $h] = getimagesizefromstring($result->contents);
    expect($w)->toBe(900)->and($h)->toBe(1200); // normalized, uncropped
});

it('applies EXIF orientation BEFORE pose, and hands the detector image BYTES (not a URL)', function () {
    // Fixture is stored 120x60 with EXIF orientation 6 (display = rotate 90° CW).
    $original = file_get_contents(base_path('tests/Fixtures/exif_orientation_6.jpg'));
    $fake = FakePoseDetector::returning(PoseFixtures::empty());

    preprocessorWith($fake)->process($original, 'dresses');

    // The detector saw the UPRIGHT image (60x120), proving orientation ran first.
    [$w, $h] = getimagesizefromstring($fake->receivedContents);
    expect($w)->toBe(60)->and($h)->toBe(120);

    // It received real image bytes, never a URL (privacy: no public/pre-signed link).
    expect($fake->receivedContents)->not->toStartWith('http');
});

it('falls back to the original photo on a fail-open skip without logging image content', function () {
    config(['services.pose.failure_mode' => 'fail_open']);
    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = $message->message;
    });

    preprocessorWith(FakePoseDetector::throwing())->process(PoseFixtures::jpeg(640, 480), 'dresses');

    // Exactly one safe, content-free warning about the skip.
    expect($logged)->toContain('[preprocess] pose service unavailable; skipping preprocessing (fail-open)');
});

/*
 * Appearance enhancement (9.5): runs AFTER validation and BEFORE crop, hands the
 * crop its output, and is fail-open. Enhancer is a FakeImageEnhancer (no service).
 */

it('enhances after validation and hands the ENHANCED bytes to the crop', function () {
    // The enhancer returns a non-image marker. The cropper cannot decode it, so it
    // falls back to exactly those bytes — proving the crop ran on the enhancer's
    // output (order: validation -> enhance -> crop) rather than the normalized image.
    $enhancer = FakeImageEnhancer::returning('NOT-AN-IMAGE-MARKER');
    $pre = preprocessorWithEnhancer(
        FakePoseDetector::returning(PoseFixtures::fullBody(1000, 1500)),
        $enhancer,
    );

    $result = $pre->process(PoseFixtures::jpeg(1000, 1500), 'dresses');

    // Enhancer ran once, on the normalized (decodable, oriented/resized) image.
    expect($enhancer->calls)->toBe(1);
    [$w, $h] = getimagesizefromstring($enhancer->receivedContents);
    expect($w)->toBe(1000)->and($h)->toBe(1500);

    expect($result->isProcessed())->toBeTrue()
        ->and($result->contents)->toBe('NOT-AN-IMAGE-MARKER');
});

it('falls back to the unenhanced photo when enhancement fails (fail-open) without logging image content', function () {
    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = $message->message;
    });

    $pre = preprocessorWithEnhancer(
        FakePoseDetector::returning(PoseFixtures::fullBody(1000, 1500)),
        FakeImageEnhancer::throwing(),
    );

    $result = $pre->process(PoseFixtures::jpeg(1000, 1500), 'dresses');

    // Generation is NOT blocked: the photo is still processed (normalized+cropped).
    expect($result->isProcessed())->toBeTrue();
    [$w, $h] = getimagesizefromstring($result->contents);
    expect($w)->toBeLessThan(1000)->and($w)->toBeGreaterThan(0);

    expect($logged)->toContain('[preprocess] image enhancement failed; using unenhanced photo (fail-open)');
});

it('binds the no-op enhancer when both steps are disabled (default suite path)', function () {
    // phpunit.xml forces both flags off -> no HTTP call, byte-identical to pre-9.5.
    expect(app(PhotoEnhancer::class))->toBeInstanceOf(NullImageEnhancer::class);
});

it('binds the HTTP enhancer when a step is enabled and the pose service is active', function () {
    config([
        'photo.enhancement.lighting.enabled' => true,
        'services.pose.driver' => 'mediapipe',
    ]);

    expect(app(PhotoEnhancer::class))->toBeInstanceOf(PoseServiceImageEnhancer::class);
});

it('fits the processed photo to the configured output aspect (no slider bars)', function () {
    // Source is 2:3; the engine outputs 3:4. After cropping, the photo is fitted to
    // 3:4 so the image we send (and the "Before") matches the result frame.
    config(['photo.output.aspect' => '3:4']);
    $pre = preprocessorWith(FakePoseDetector::returning(PoseFixtures::fullBody(1000, 1500)));

    $result = $pre->process(PoseFixtures::jpeg(1000, 1500), 'dresses');

    expect($result->isProcessed())->toBeTrue();
    [$w, $h] = getimagesizefromstring($result->contents);
    expect($w / $h)->toBeGreaterThan(0.74)->and($w / $h)->toBeLessThan(0.76); // ~3:4
});
