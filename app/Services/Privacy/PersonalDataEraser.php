<?php

namespace App\Services\Privacy;

use App\Models\Generation;
use App\Models\Lead;
use App\Models\OtpCode;
use App\Models\User;
use App\Models\WelcomeGrant;
use App\Services\Credits\CreditService;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Erases personal data — the single place that knows WHAT belongs to a person and
 * in what order it has to go. Used by both retention cleanup (time-based) and
 * erasure on request (person-based) so the two can never drift apart.
 *
 * Two rules hold everywhere in this class:
 *
 * 1. Stored images go BEFORE their rows. The row is the only record of the storage
 *    key, so deleting it first would strand the object in the bucket until the
 *    lifecycle rule caught up — the difference between "erased" and "expires
 *    eventually".
 * 2. welcome_grants is NEVER touched. It holds an irreversible hash of an e-mail
 *    address and is kept forever, deliberately surviving account deletion, so the
 *    one-off welcome bonus cannot be claimed twice with the same address. The
 *    privacy policy discloses this in as many words. Do not add it here.
 *
 * Object deletes are best effort: a storage failure must not abort an erasure
 * (the 72h lifecycle rule is the backstop), but it is counted and reported so a
 * silent failure cannot look like success.
 */
class PersonalDataEraser
{
    /** Objects we tried to delete but could not. */
    private int $failedObjects = 0;

    /** How many stored objects failed to delete during the last call. */
    public function failedObjectDeletes(): int
    {
        return $this->failedObjects;
    }

    /**
     * Erase one generation: its client photo, the upload it was processed from,
     * its result images, then the row. Several looks share one upload; deleting
     * a missing object is a no-op, so the second erasure is harmless.
     */
    public function eraseGeneration(Generation $generation): void
    {
        $disk = (string) config('fitting_room.photo.disk');

        $keys = array_filter(array_merge(
            [$generation->client_photo, $generation->source_photo],
            array_values((array) ($generation->result_keys ?? [])),
        ));

        foreach ($keys as $key) {
            $this->deleteObject($disk, (string) $key);
        }

        $generation->delete();
    }

    /**
     * Erase one lead and everything generated from it.
     *
     * Generations are removed rather than orphaned: the foreign key would merely
     * null lead_id, leaving a row that still points at a private photo with no
     * owner to trace it back to.
     *
     * @return int generations erased along with the lead
     */
    public function eraseLead(Lead $lead): int
    {
        $count = 0;

        Generation::query()->where('lead_id', $lead->id)->cursor()->each(function (Generation $generation) use (&$count) {
            $this->eraseGeneration($generation);
            $count++;
        });

        $lead->delete();

        return $count;
    }

    /**
     * Erase a member account: their leads (with the generations behind them), any
     * generation reached only through a lead we already deleted, their outstanding
     * one-time codes, and the account row. The credit ledger goes with the row via
     * its cascade.
     *
     * welcome_grants survives — see the class docblock.
     *
     * @return array{leads: int, generations: int}
     */
    public function eraseUser(User $user): array
    {
        $leads = 0;
        $generations = 0;

        $user->leads()->cursor()->each(function (Lead $lead) use (&$leads, &$generations) {
            $generations += $this->eraseLead($lead);
            $leads++;
        });

        // Outstanding sign-in codes are keyed by address, not by user id, so
        // they would otherwise outlive the account they belong to.
        if ($user->email !== null) {
            OtpCode::query()->where('email', $user->email)->delete();

            // The welcome-grant hash stays: deleting it here would hand the bonus
            // back to anyone who erases and re-registers, which is the whole
            // reason it exists. It is stamped instead, so the retention sweep can
            // drop it once it has outlived the account it belonged to.
            WelcomeGrant::query()
                ->where('identity_hash', app(CreditService::class)->identityHash($user->email))
                ->whereNull('account_removed_at')
                ->update(['account_removed_at' => now()]);
        }

        // The profile picture lives outside the 72-hour photo sweep, so nothing
        // else would ever remove it.
        if ($user->avatar_path) {
            $this->deleteObject((string) config('fitting_room.avatar.disk'), $user->avatar_path);
        }

        $user->delete();

        return ['leads' => $leads, 'generations' => $generations];
    }

    /** Reset the failure counter before a fresh run. */
    public function resetCounters(): void
    {
        $this->failedObjects = 0;
    }

    /**
     * Best-effort object delete. Never throws and never logs the key itself (it
     * identifies a private photo); the caller reports the count.
     */
    private function deleteObject(string $disk, string $key): void
    {
        if ($key === '') {
            return;
        }

        try {
            Storage::disk($disk)->delete($key);
        } catch (Throwable $e) {
            $this->failedObjects++;
        }
    }
}
