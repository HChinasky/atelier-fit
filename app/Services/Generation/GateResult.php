<?php

namespace App\Services\Generation;

/**
 * Outcome of the generation gate: either allowed, or rejected with the HTTP
 * status and a user-facing message (no internal detail leaked).
 *
 * `code` is a machine-readable rejection reason for the front-end funnel
 * (e.g. `signup_required` opens the WhatsApp login, `credits_exhausted` the
 * paywall) — the message alone is for humans and must not be parsed.
 *
 * `creditTransactionId` rides along on an allowed MEMBER request: the gate
 * spends before the Generation row exists, so the controller attaches the
 * generation to this ledger row right after creating it (refund lookup key).
 */
final readonly class GateResult
{
    private function __construct(
        public bool $allowed,
        public int $status = 202,
        public string $message = '',
        public ?string $code = null,
        public ?int $creditTransactionId = null,
    ) {}

    public static function allow(?int $creditTransactionId = null): self
    {
        return new self(true, creditTransactionId: $creditTransactionId);
    }

    public static function reject(int $status, string $message, ?string $code = null): self
    {
        return new self(false, $status, $message, $code);
    }
}
