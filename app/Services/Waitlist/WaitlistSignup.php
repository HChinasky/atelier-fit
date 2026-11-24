<?php

namespace App\Services\Waitlist;

use App\Models\Lead;
use App\Services\Analytics\AnalyticsService;
use App\Services\Lead\SessionAttribution;
use Illuminate\Http\Request;

/**
 * The pre-launch waiting list: an address typed into the sign-in field while
 * FEATURE_COMING_SOON is on is kept, so the person can be told when the
 * atelier opens — and nothing else happens to it. No code is sent, no account
 * is made.
 *
 * It is a lead, not a table of its own: the same row shape, tagged "waitlist",
 * so the machinery that already exists for a contact — the 12-month retention
 * sweep, erasure by address (user:erase), the external notifier seam, campaign
 * attribution — covers it without a second copy of any of it. One row per
 * address: typing it twice refreshes the row rather than doubling it.
 */
class WaitlistSignup
{
    public const TAG = 'waitlist';

    public function __construct(private readonly AnalyticsService $analytics) {}

    public function record(string $email, Request $request): Lead
    {
        $existing = Lead::query()
            ->where('channel', 'email')
            ->where('contact', $email)
            ->whereJsonContains('tags', self::TAG)
            ->first();

        if ($existing !== null) {
            // "Last contact with us" is what retention counts from.
            $existing->touch();

            return $existing;
        }

        $lead = Lead::create([
            'channel' => 'email',
            'contact' => $email,
            'tags' => [self::TAG],
            'ip' => $request->ip(),
            ...SessionAttribution::forLead($request),
        ]);

        // The same identity rule as a try-on lead: the visitor becomes the
        // lead's pseudonymous hash, the address itself never reaches analytics.
        $this->analytics->aliasLead($lead);
        $this->analytics->capture('waitlist_joined', [
            'channel' => 'email',
        ], $this->analytics->leadDistinctId($lead->id));

        return $lead;
    }

    /**
     * An address the way the coming-soon page shows it back: the first letter
     * and the domain, the rest of the mailbox hidden. Enough to recognise your
     * own address, not enough for the next person at the screen to copy it.
     */
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return $email;
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at);

        return mb_substr($local, 0, 1).str_repeat('•', max(2, mb_strlen($local) - 1)).$domain;
    }
}
