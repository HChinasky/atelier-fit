<?php

namespace App\Services\Pose;

/**
 * Default-safe PoseDetector that performs no I/O. Used in tests and in any
 * environment where the pose microservice is not running (POSE_DRIVER=null), so
 * the application and its test suite never depend on a live service.
 *
 * It returns a predictable "no person detected" result — never throws — so a
 * fail-open flow proceeds and a fail-closed flow can treat it as "no pose data"
 * without special-casing. It makes NO network call.
 */
class NullPoseDetector implements PoseDetector
{
    public function detect(string $imageContents, string $filename = 'photo.jpg'): PoseResult
    {
        return new PoseResult(
            personCount: 0,
            imageWidth: 0,
            imageHeight: 0,
            mainPerson: null,
            bboxConfidenceThreshold: (float) config('services.pose.bbox_confidence_threshold', 0.5),
        );
    }
}
