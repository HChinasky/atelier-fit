<?php

namespace App\Services\Pose;

/**
 * Degradation policy for when the pose service is unavailable (down, timing out,
 * 5xx). The detector always signals failure by throwing PoseDetectionException;
 * this enum says what the FLOW should do about it — and is applied by the photo
 * validator (9.2) / generation flow (9.5), not here.
 *
 * Default is FailOpen (see fromConfig): pose detection is an OPTIONAL
 * preprocessing/validation enhancement, not core. If this single CPU microservice
 * blips, the funnel must keep working — a visitor should still be able to
 * generate. Budget stays protected regardless by the lead-gate and the FASHN
 * spend cap, so failing open cannot blow costs; the worst case is simply the
 * pre-9.2 behaviour (an unvalidated photo reaches FASHN). FailClosed is the
 * stricter alternative (block generation with a "service temporarily
 * unavailable" message) for operators who prefer to never spend a credit on an
 * unvalidated photo; flip it in one env var.
 */
enum PoseFailureMode: string
{
    case FailOpen = 'fail_open';
    case FailClosed = 'fail_closed';

    /**
     * Resolve the configured policy, defaulting to FailOpen for an unset or
     * unrecognised value.
     */
    public static function fromConfig(): self
    {
        return self::tryFrom((string) config('services.pose.failure_mode')) ?? self::FailOpen;
    }

    /**
     * Whether a pose-service failure should block generation (fail-closed) or be
     * tolerated so the flow proceeds without pose data (fail-open).
     */
    public function blocksGeneration(): bool
    {
        return $this === self::FailClosed;
    }
}
