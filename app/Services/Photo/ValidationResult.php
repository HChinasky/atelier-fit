<?php

namespace App\Services\Photo;

/**
 * Outcome of the photo suitability gate (9.2): a go/no-go plus, on failure, a
 * machine-readable reason and its translatable message key. Decoupled from any
 * HTTP/UX concern so the flow (9.5) decides how to present it.
 *
 * `validated` distinguishes a real verdict from a pass-through: when there is no
 * usable pose data (the service was unavailable and the flow fails open), the
 * result is passed=true with validated=false — the photo is let through WITHOUT
 * having been judged.
 */
final readonly class ValidationResult
{
    private function __construct(
        public bool $passed,
        public ?ValidationFailureReason $reason,
        public ?string $messageKey,
        public bool $validated,
    ) {}

    public static function passed(): self
    {
        return new self(passed: true, reason: null, messageKey: null, validated: true);
    }

    public static function failed(ValidationFailureReason $reason): self
    {
        return new self(
            passed: false,
            reason: $reason,
            messageKey: $reason->messageKey(),
            validated: true,
        );
    }

    /**
     * No usable pose data (e.g. the detector was unavailable and the flow fails
     * open). Not judged — let the photo through, flagged as not validated.
     */
    public static function notValidated(): self
    {
        return new self(passed: true, reason: null, messageKey: null, validated: false);
    }
}
