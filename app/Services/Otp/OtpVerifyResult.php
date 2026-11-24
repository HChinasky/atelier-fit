<?php

namespace App\Services\Otp;

/**
 * Outcome of an OtpService::verify() attempt. attemptsLeft feeds the UI copy
 * ("Invalid code — 1 attempt left"); retryAfterSeconds accompanies a block.
 */
final readonly class OtpVerifyResult
{
    private function __construct(
        public OtpVerifyStatus $status,
        /** Wrong entries still allowed on the active code (Invalid case). */
        public int $attemptsLeft = 0,
        /** Seconds until the address unblocks (Blocked case; 0 otherwise). */
        public int $retryAfterSeconds = 0,
    ) {}

    public static function verified(): self
    {
        return new self(OtpVerifyStatus::Verified);
    }

    public static function invalid(int $attemptsLeft): self
    {
        return new self(OtpVerifyStatus::Invalid, attemptsLeft: max(0, $attemptsLeft));
    }

    public static function expired(): self
    {
        return new self(OtpVerifyStatus::Expired);
    }

    public static function blocked(int $retryAfterSeconds): self
    {
        return new self(OtpVerifyStatus::Blocked, retryAfterSeconds: max(1, $retryAfterSeconds));
    }

    public function isVerified(): bool
    {
        return $this->status === OtpVerifyStatus::Verified;
    }
}
