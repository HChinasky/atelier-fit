<?php

namespace App\Services\Photo;

use App\Services\Pose\Person;
use App\Services\Pose\PoseResult;

/**
 * Default photo gate. Applies, in order: pass-through on empty pose data,
 * person-count rules, face-only, frontality, then category-dependent full-body.
 * All thresholds come from config('photo.validation') — no magic numbers.
 */
class DefaultPhotoValidator implements PhotoValidator
{
    public function validate(PoseResult $pose, string $category): ValidationResult
    {
        // No usable pose data (Null stub / fail-open outage): we cannot judge, so
        // pass the photo through, flagged as not validated. Never crash the flow.
        if ($pose->isEmpty()) {
            return ValidationResult::notValidated();
        }

        if ($pose->personCount === 0) {
            return ValidationResult::failed(ValidationFailureReason::NoPerson);
        }

        if ($pose->personCount > 1) {
            return ValidationResult::failed(ValidationFailureReason::MultiplePeople);
        }

        $person = $pose->mainPerson;
        if ($person === null) {
            // The 9.1 contract guarantees a person when count === 1; be defensive.
            return ValidationResult::failed(ValidationFailureReason::NoPerson);
        }

        $threshold = (float) config('photo.validation.landmark_confidence', 0.5);

        $faceVisible = $this->confident($person, 'nose', $threshold);
        $leftShoulder = $this->confident($person, 'left_shoulder', $threshold);
        $rightShoulder = $this->confident($person, 'right_shoulder', $threshold);

        // FACE_ONLY: a selfie-by-face — the face is visible but the torso is not.
        if ($faceVisible && ! $leftShoulder && ! $rightShoulder) {
            return ValidationResult::failed(ValidationFailureReason::FaceOnly);
        }

        // FRONTAL: both shoulders and both hips reliably visible, and the
        // shoulders are horizontally separated (a profile collapses that gap).
        $leftHip = $this->confident($person, 'left_hip', $threshold);
        $rightHip = $this->confident($person, 'right_hip', $threshold);
        $torsoVisible = $leftShoulder && $rightShoulder && $leftHip && $rightHip;

        if (! $torsoVisible || ! $this->shouldersSeparated($pose, $person)) {
            return ValidationResult::failed(ValidationFailureReason::NotFrontal);
        }

        // FULL BODY (category-dependent): require both feet by CONFIDENCE, not
        // mere presence — off-frame feet come back extrapolated at ~0.02. A foot
        // counts when its ankle, heel or toe is confident: a crossed leg in dark
        // tights can leave the ankle just under the bar while the heel clears it.
        if ($this->requiresFullBody($category)) {
            $feet = $this->footVisible($person, 'left', $threshold)
                && $this->footVisible($person, 'right', $threshold);

            if (! $feet) {
                return ValidationResult::failed(ValidationFailureReason::NotFullBody);
            }
        }

        return ValidationResult::passed();
    }

    /**
     * Whether a named landmark exists and is at/above the confidence threshold.
     */
    private function confident(Person $person, string $name, float $threshold): bool
    {
        $landmark = $person->landmark($name);

        return $landmark !== null && $landmark->confidence >= $threshold;
    }

    /**
     * Whether one side's foot is in frame: its ankle, heel or toe confident.
     */
    private function footVisible(Person $person, string $side, float $threshold): bool
    {
        foreach (['ankle', 'heel', 'foot_index'] as $point) {
            if ($this->confident($person, "{$side}_{$point}", $threshold)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the shoulders are far enough apart horizontally (relative to image
     * width) to indicate a frontal pose rather than a profile.
     */
    private function shouldersSeparated(PoseResult $pose, Person $person): bool
    {
        $left = $person->landmark('left_shoulder');
        $right = $person->landmark('right_shoulder');

        if ($left === null || $right === null || $pose->imageWidth <= 0) {
            return false;
        }

        $ratio = abs($left->x - $right->x) / $pose->imageWidth;

        return $ratio >= (float) config('photo.validation.min_shoulder_separation_ratio', 0.05);
    }

    private function requiresFullBody(string $category): bool
    {
        $categories = (array) config('photo.validation.full_body_categories', []);

        return in_array($category, $categories, true);
    }
}
