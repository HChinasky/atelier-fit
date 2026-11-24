<?php

namespace App\Services\Credits;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Models\WelcomeGrant;
use App\Services\Otp\EmailAddress;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Member try-on credits (Stage B3) over the append-only credit_transactions
 * ledger. Three operations, each safe to race:
 *
 * - grantWelcome — once per E-MAIL ADDRESS ever (welcome_grants unique
 *   index), so deleting the account and re-registering the same address
 *   cannot farm the +4 again.
 * - spend — balance check + debit serialized on the user row lock, so two
 *   concurrent generations cannot both spend the last credit.
 * - refundForGeneration — compensates a failed generation's spend; the
 *   (generation_id, reason) unique index makes retries a no-op.
 */
class CreditService
{
    /** The user's current balance: the ledger sum, never a cached column. */
    public function balance(User $user): int
    {
        return (int) CreditTransaction::where('user_id', $user->id)->sum('delta');
    }

    /**
     * Grant the welcome credits unless this ADDRESS has ever received them.
     * Returns whether the grant happened.
     */
    public function grantWelcome(User $user, string $email): bool
    {
        try {
            DB::transaction(function () use ($user, $email) {
                // The unique identity_hash insert IS the once-ever gate; the
                // ledger row rides in the same transaction so a lost race
                // rolls both back together.
                WelcomeGrant::create(['identity_hash' => $this->identityHash($email)]);

                CreditTransaction::create([
                    'user_id' => $user->id,
                    'delta' => (int) config('membership.welcome_credits'),
                    'reason' => CreditTransaction::REASON_WELCOME,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Debit `amount` credits, or null when the balance does not cover it.
     * The generation is attached later via attachGeneration() — the gate
     * spends BEFORE the Generation row exists.
     */
    public function spend(User $user, int $amount): ?CreditTransaction
    {
        $amount = max(1, $amount);

        return DB::transaction(function () use ($user, $amount) {
            // Serialize concurrent spends of one user on their row lock; the
            // balance is then read inside the critical section.
            User::whereKey($user->id)->lockForUpdate()->first();

            if ($this->balance($user) < $amount) {
                return null;
            }

            return CreditTransaction::create([
                'user_id' => $user->id,
                'delta' => -$amount,
                'reason' => CreditTransaction::REASON_SPEND,
            ]);
        });
    }

    /** Tie a just-made spend to the generation it paid for (refund lookup key). */
    public function attachGeneration(CreditTransaction $spend, Generation $generation): void
    {
        $spend->update(['generation_id' => $generation->id]);
    }

    /**
     * Give back what a failed generation spent. No-op for guest generations
     * (no spend row) and on repeats (unique (generation_id, reason)).
     */
    public function refundForGeneration(Generation $generation): bool
    {
        $spend = CreditTransaction::where('generation_id', $generation->id)
            ->where('reason', CreditTransaction::REASON_SPEND)
            ->first();

        if ($spend === null) {
            return false;
        }

        try {
            // Wrapped so a unique-index rejection rolls back to a savepoint
            // instead of poisoning any surrounding transaction (Postgres
            // aborts the whole tx on a statement error otherwise).
            DB::transaction(function () use ($spend, $generation) {
                CreditTransaction::create([
                    'user_id' => $spend->user_id,
                    'generation_id' => $generation->id,
                    'delta' => abs($spend->delta),
                    'reason' => CreditTransaction::REASON_REFUND,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return false; // already refunded — retried failure hook
        }

        Log::info('[credits] refunded failed generation', [
            'generation_id' => $generation->id,
            'user_id' => $spend->user_id,
            'amount' => abs($spend->delta),
        ]);

        return true;
    }

    /**
     * The non-reversible identity of an e-mail address for welcome_grants: an
     * HMAC keyed by the app secret, so the un-deletable marker table never
     * holds a readable address and rows cannot be brute-forced without the
     * key. Hashed in normalised form so spelling never splits a grant.
     */
    public function identityHash(string $email): string
    {
        return hash_hmac('sha256', EmailAddress::normalize($email), (string) config('app.key'));
    }
}
