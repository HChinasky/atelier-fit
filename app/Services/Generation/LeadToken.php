<?php

namespace App\Services\Generation;

use App\Models\Lead;

/**
 * Proof of ownership for MEMBER leads on the stateless generation API.
 *
 * Member generations debit a credit ledger, which turns lead_id enumeration
 * from a nuisance into theft: anyone posting a member's lead_id would burn
 * that member's credits. The token closes this: it is an HMAC over the lead
 * and its owner, minted server-side only where the member's session is live
 * (the fitting-room page render), and required by the API for any lead that
 * has an owner. Guest leads (user_id NULL) neither need nor accept one.
 *
 * Deliberately NOT a session mechanism: the API stays stateless and CSRF-free
 * for guests, and a stolen page's token is scoped to one lead/owner pair.
 */
class LeadToken
{
    public function make(Lead $lead): string
    {
        return hash_hmac(
            'sha256',
            sprintf('member-lead|%d|%d', $lead->id, (int) $lead->user_id),
            (string) config('app.key'),
        );
    }

    public function verify(Lead $lead, ?string $token): bool
    {
        if ($lead->user_id === null) {
            return true; // guest lead — nothing to prove
        }

        return is_string($token) && $token !== '' && hash_equals($this->make($lead), $token);
    }
}
