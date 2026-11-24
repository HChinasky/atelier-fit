<?php

namespace App\Services\Otp;

/**
 * Outcome of an OtpService::send() attempt. A plain status + optional
 * retry-after so the endpoint can map it straight onto HTTP responses and the
 * UI states drawn in the design (throttled / blocked / temporarily unavailable).
 */
final readonly class OtpSendResult
{
    private function __construct(
        public OtpSendStatus $status,
        /** Seconds until the caller may retry (throttle/block cases; 0 otherwise). */
        public int $retryAfterSeconds = 0,
    ) {}

    public static function sent(): self
    {
        return new self(OtpSendStatus::Sent);
    }

    public static function rateLimited(int $retryAfterSeconds): self
    {
        return new self(OtpSendStatus::RateLimited, max(1, $retryAfterSeconds));
    }

    public static function blocked(int $retryAfterSeconds): self
    {
        return new self(OtpSendStatus::Blocked, max(1, $retryAfterSeconds));
    }

    public static function unavailable(): self
    {
        return new self(OtpSendStatus::Unavailable);
    }

    public static function failed(): self
    {
        return new self(OtpSendStatus::Failed);
    }

    public function wasSent(): bool
    {
        return $this->status === OtpSendStatus::Sent;
    }
}
