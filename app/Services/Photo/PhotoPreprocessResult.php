<?php

namespace App\Services\Photo;

/**
 * Outcome of the input-photo preprocessing pipeline (9.5), run at generation
 * submit before anything is queued or charged. Four mutually exclusive cases:
 *
 *  - PROCESSED:   the photo was normalized (and cropped when pose allowed). Send
 *                 `contents` to FASHN.
 *  - REJECTED:    a real suitability verdict — the photo is unfit. Show the user
 *                 `reason` and DO NOT generate / spend a credit. Independent of
 *                 the pose failure mode.
 *  - SKIPPED:     a pose-service / preprocessing FAILURE under the fail-open
 *                 policy — proceed with the ORIGINAL, unprocessed photo (the
 *                 pre-Phase-9 funnel). The funnel keeps working.
 *  - UNAVAILABLE: the same failure under the fail-closed policy — block the
 *                 generation with a "temporarily unavailable" message.
 */
final readonly class PhotoPreprocessResult
{
    public const PROCESSED = 'processed';

    public const REJECTED = 'rejected';

    public const SKIPPED = 'skipped';

    public const UNAVAILABLE = 'unavailable';

    private function __construct(
        public string $outcome,
        public ?string $contents = null,
        public ?string $format = null,
        public ?ValidationFailureReason $reason = null,
        public ?CropBox $cropBox = null,
    ) {}

    public static function processed(string $contents, string $format, ?CropBox $cropBox = null): self
    {
        return new self(self::PROCESSED, contents: $contents, format: $format, cropBox: $cropBox);
    }

    public static function rejected(ValidationFailureReason $reason): self
    {
        return new self(self::REJECTED, reason: $reason);
    }

    public static function skipped(): self
    {
        return new self(self::SKIPPED);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE);
    }

    public function isProcessed(): bool
    {
        return $this->outcome === self::PROCESSED;
    }

    public function isRejected(): bool
    {
        return $this->outcome === self::REJECTED;
    }

    public function isSkipped(): bool
    {
        return $this->outcome === self::SKIPPED;
    }

    public function isUnavailable(): bool
    {
        return $this->outcome === self::UNAVAILABLE;
    }
}
