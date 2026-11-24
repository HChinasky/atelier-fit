<?php

namespace App\Services\Pose;

/**
 * Structured pose-detection result, returned by PoseDetector::detect(). Mirrors
 * the pose service's response (person_count, image_dimensions, main_person,
 * bbox_confidence_threshold) but as a typed object so 9.2 (validation) and 9.4
 * (cropping) never touch a raw array.
 *
 * This is pure data: it makes NO go/no-go judgement. hasMainPerson() is a plain
 * accessor, not a validation verdict.
 */
final readonly class PoseResult
{
    public function __construct(
        public int $personCount,
        public int $imageWidth,
        public int $imageHeight,
        public ?Person $mainPerson,
        public float $bboxConfidenceThreshold,
    ) {}

    public function hasMainPerson(): bool
    {
        return $this->mainPerson !== null;
    }

    /**
     * Whether this result carries no usable pose data at all — the signature the
     * NullPoseDetector produces (no person, no dimensions). Distinct from a real
     * "looked and found nobody" result, which still reports image dimensions.
     * Consumers (9.2) treat an empty result as "could not validate" rather than
     * "no person", so a fail-open flow can pass the photo through.
     */
    public function isEmpty(): bool
    {
        return $this->personCount === 0
            && $this->mainPerson === null
            && $this->imageWidth === 0
            && $this->imageHeight === 0;
    }
}
