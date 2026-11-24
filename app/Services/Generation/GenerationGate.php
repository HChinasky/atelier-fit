<?php

namespace App\Services\Generation;

use App\Models\Generation;
use App\Models\Lead;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Budget protection for FASHN generations. Every check runs BEFORE the provider
 * is ever called, so a rejected request can never spend a credit.
 *
 * Priority (lead gate is enforced by the caller before this point):
 *   1. lead gate     — a Lead is required to even call attempt(); no lead → the
 *      controller never reaches here. This is the primary protection.
 *   2. global cap    — a hard ceiling on total FASHN CREDITS across all users per
 *      period; the real safety valve against a runaway burning the balance.
 *      Always wins (its 503 beats any funnel/limit rejection).
 *   3. funnel budget — WHO pays for this generation:
 *      - flag off (or flag on, guest lead): the IP+contact flood guard, as ever.
 *        NOT real budget protection (bypassable by changing IP/email); it only
 *        blunts accidental flooding.
 *      - flag on, guest lead: ADDITIONALLY the "2 free" funnel allowance —
 *        lifetime per contact, counted in the DB (failed generations excluded,
 *        so a failure never eats a free try). Exhausted → signup_required.
 *      - flag on, member lead (user_id set): the member's credit ledger — one
 *        credit per generation unit. The daily flood guard is skipped: credits
 *        already bound the spend and the identity is verified, so a 3/day cap
 *        would only strangle paying members.
 *
 * The global cap counts ENGINE CREDITS, not generations: `cost` is num_samples
 * doubled by background (up to 8), while `units` — what funnels and ledgers
 * count — is generations (1, or N for an N-garment outfit).
 */
class GenerationGate
{
    private const GLOBAL_KEY = 'generation:global';

    private const PER_USER_PERIOD_SECONDS = 86400;

    public function __construct(private readonly CreditService $credits) {}

    /**
     * @param  int  $cost  Engine credits this request will spend against the global
     *                     cap. Single try-on: num_samples × (with_background ? 2 : 1).
     *                     Outfit chain: one credit per garment (N sequential calls).
     * @param  int  $units  How many GENERATIONS this request counts as against the
     *                      per-user flood guard / funnel allowance / credit ledger.
     *                      1 for a single try-on; N for an N-garment outfit (a set is
     *                      not a loophole around any limit). The whole request is
     *                      checked atomically — if all N units do not fit, nothing is
     *                      claimed and the chain never starts.
     */
    public function attempt(Lead $lead, string $ip, int $cost = 1, int $units = 1): GateResult
    {
        $cost = max(1, $cost);
        $units = max(1, $units);
        $cap = (int) config('fitting_room.generation.global_cap');

        // 2) Global hard cap — priority early-out: if this generation's cost would
        //    push us over the cap, reject here, BEFORE any funnel/limit check (so
        //    the cap's 503 wins over a 429/402/403) and before claiming anything.
        //    The authoritative, atomic check is claimGlobalCredits() below; this
        //    peek just short-circuits the common case.
        if ((int) Cache::get(self::GLOBAL_KEY, 0) + $cost > $cap) {
            return $this->capReached($cap);
        }

        $membership = (bool) config('features.membership');

        if ($membership && $lead->user_id !== null) {
            return $this->attemptAsMember($lead, $cap, $cost, $units);
        }

        // 3a) Funnel allowance (flag on, guests only): N free try-ons per contact,
        //     lifetime. Counted in the DB across ALL of this contact's leads, so a
        //     re-submitted contact form does not reset it; failed generations are
        //     excluded (a failure must not eat a free try).
        if ($membership && $this->guestAllowanceExhausted($lead, $units)) {
            return GateResult::reject(
                403,
                __('generation.signup_required', ['count' => (int) config('membership.welcome_credits')]),
                'signup_required',
            );
        }

        // 3b) Per-user flood guard (IP + captured contact). A flood guard only —
        //     NOT real budget protection; the lead gate and global cap are.
        $limit = (int) config('fitting_room.generation.per_user_daily_limit');
        $userKey = $this->userKey($ip, $lead->contact);

        // Reject when the request's units would push this user OVER the daily limit
        // — checked for ALL units up front (an N-garment outfit must fit whole). For
        // units = 1 this is exactly the previous "already at limit" check.
        if (RateLimiter::attempts($userKey) + $units > $limit) {
            return GateResult::reject(429, __('generation.daily_limit'));
        }

        // Atomically claim this request's credits under the global cap. Because
        // the increment-and-check is a single indivisible step, two concurrent
        // requests on the cap boundary cannot both "see room": only those whose
        // atomic count stays within `cap` win.
        if (! $this->claimGlobalCredits($cap, $cost)) {
            return $this->capReached($cap);
        }

        // Credits claimed — record the per-user spend (request fully allowed): one
        // hit per generation unit, so an outfit consumes N of the daily allowance.
        for ($i = 0; $i < $units; $i++) {
            RateLimiter::hit($userKey, self::PER_USER_PERIOD_SECONDS);
        }

        return GateResult::allow();
    }

    /**
     * Member path: global cap, then the credit ledger. Claim order matters —
     * global credits first (a cheap cache op to unwind), the ledger debit
     * second; if the ledger cannot cover it, the cache claim is given back.
     */
    private function attemptAsMember(Lead $lead, int $cap, int $cost, int $units): GateResult
    {
        if (! $this->claimGlobalCredits($cap, $cost)) {
            return $this->capReached($cap);
        }

        $spend = $this->credits->spend($lead->user, $units);

        if ($spend === null) {
            Cache::decrement(self::GLOBAL_KEY, $cost);

            return GateResult::reject(
                402,
                __('generation.credits_exhausted'),
                'credits_exhausted',
            );
        }

        return GateResult::allow($spend->id);
    }

    /**
     * Has this CONTACT used up its lifetime free try-ons? Counted across all
     * GUEST leads with the same (normalized) contact — excluding failed
     * generations and excluding member leads (members pay from the ledger).
     */
    private function guestAllowanceExhausted(Lead $lead, int $units): bool
    {
        $free = (int) config('membership.guest_free_generations');

        $used = Generation::query()
            ->whereHas('lead', function ($query) use ($lead) {
                $query->whereNull('user_id')
                    ->whereRaw('lower(trim(contact)) = ?', [mb_strtolower(trim($lead->contact))]);
            })
            ->where('status', '!=', 'failed')
            ->count();

        return $used + $units > $free;
    }

    /**
     * Atomically claim `cost` credits under the global cap.
     *
     * add() creates the counter with the window TTL exactly once (NX), so the
     * period still resets when the window ends. increment($cost) is an atomic
     * INCRBY (a single Redis op in production); the decision is made on the value
     * it returns, so the counter can never let more than `cap` credits through —
     * no check-then-increment race. On overshoot we give the credits straight
     * back (atomic DECRBY) so a rejected expensive request doesn't permanently
     * sterilise capacity that a cheaper later request could have used.
     */
    private function claimGlobalCredits(int $cap, int $cost): bool
    {
        Cache::add(self::GLOBAL_KEY, 0, (int) config('fitting_room.generation.global_cap_period_seconds'));

        $count = (int) Cache::increment(self::GLOBAL_KEY, $cost);

        if ($count <= $cap) {
            return true;
        }

        Cache::decrement(self::GLOBAL_KEY, $cost);

        return false;
    }

    private function capReached(int $cap): GateResult
    {
        Log::warning('[generation-gate] global generation cap reached; refusing new generations', [
            'cap' => $cap,
        ]);

        return GateResult::reject(503, __('generation.unavailable'));
    }

    private function userKey(string $ip, string $contact): string
    {
        return 'generation:user:'.md5($ip.'|'.mb_strtolower(trim($contact)));
    }
}
