<?php

use App\Services\Pose\MediaPipePoseDetector;
use App\Services\Pose\NullPoseDetector;
use App\Services\Pose\PoseDetector;
use App\Services\Pose\PoseFailureMode;

/*
 * Container binding selection, the Null stub, and the degradation policy default.
 */

it('binds the real MediaPipe detector by default', function () {
    config(['services.pose.driver' => 'mediapipe']);

    expect(app(PoseDetector::class))->toBeInstanceOf(MediaPipePoseDetector::class);
});

it('binds the Null stub when POSE_DRIVER=null', function () {
    config(['services.pose.driver' => 'null']);

    expect(app(PoseDetector::class))->toBeInstanceOf(NullPoseDetector::class);
});

it('NullPoseDetector returns a predictable empty result and makes no call', function () {
    $result = (new NullPoseDetector)->detect('RAW-IMAGE-BYTES', 'me.jpg');

    expect($result->personCount)->toBe(0)
        ->and($result->mainPerson)->toBeNull()
        ->and($result->hasMainPerson())->toBeFalse();
});

it('defaults the degradation policy to fail-open', function () {
    config(['services.pose.failure_mode' => null]);

    $mode = PoseFailureMode::fromConfig();

    expect($mode)->toBe(PoseFailureMode::FailOpen)
        ->and($mode->blocksGeneration())->toBeFalse();
});

it('honours a configured fail-closed policy', function () {
    config(['services.pose.failure_mode' => 'fail_closed']);

    $mode = PoseFailureMode::fromConfig();

    expect($mode)->toBe(PoseFailureMode::FailClosed)
        ->and($mode->blocksGeneration())->toBeTrue();
});
