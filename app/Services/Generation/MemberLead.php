<?php

namespace App\Services\Generation;

use App\Models\Lead;
use App\Models\User;

/**
 * The lead a signed-in member generates with, and the token that proves it
 * is theirs.
 *
 * A member's contact entered the funnel at signup, so a try-on started from
 * their account needs no contact step: the page mints (once, then reuses)
 * the member's OWNED lead and hands the browser its HMAC token, and the
 * generate endpoint debits that member's credit ledger through it. Shared by
 * the internal fitting-room page and the account dashboard so both mint the
 * same row the same way.
 *
 * Not pushed through LeadNotifier: the contact is already known.
 */
class MemberLead
{
    public function __construct(private readonly LeadToken $tokens) {}

    /**
     * @return array{id: int, token: string}|null null for a guest, or while
     *                                            membership is off
     */
    public function mint(?User $user, string $ip): ?array
    {
        if (! config('features.membership') || $user === null || $user->email === null) {
            return null;
        }

        $lead = Lead::firstOrCreate(
            ['user_id' => $user->id],
            [
                'channel' => 'email',
                'contact' => $user->email,
                'tags' => ['try-on-user', 'member'],
                'ip' => $ip,
            ],
        );

        return ['id' => $lead->id, 'token' => $this->tokens->make($lead)];
    }
}
