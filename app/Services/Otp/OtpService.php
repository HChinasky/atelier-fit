<?php

namespace App\Services\Otp;

use App\Mail\LoginCode;
use App\Models\OtpCode;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * The OTP core: issues, throttles and verifies one-time sign-in codes sent by
 * e-mail. Everything that is policy — TTL, single-use, wrong-entry blocking,
 * the layered send limits — lives here so the endpoints stay thin; delivery
 * is one Mailable handed to the configured mailer.
 *
 * Security posture:
 * - The plaintext code never touches the database: the row holds a bcrypt
 *   hash, the plaintext exists only inside the message. Nothing here ever
 *   logs a code (the 'log' MAILER is the local-only exception, by design).
 * - A code is single-use: consumption is a conditional UPDATE on consumed_at,
 *   so two racing verifies cannot both win.
 * - After max_failed_attempts wrong entries the address blocks for
 *   block_minutes (both verify AND send refuse it) — matching the "Too many
 *   attempts, try again in 3 hours" state drawn in the design.
 *
 * Send limits, layered like the generation gate (see config/otp.php):
 * requester (fingerprint+IP) 1/min and 3/hour → address 2 per window → the
 * app-wide fuse (50/min) as the safety valve against runaway/abusive sending.
 */
class OtpService
{
    private const GLOBAL_KEY = 'otp:send:global';

    /**
     * Issue a fresh code for $email (already normalised — see EmailAddress)
     * and get it on its way. $fingerprint is the client-generated browser
     * fingerprint; together with the IP it keys the per-requester limits.
     */
    public function send(string $email, string $ip, string $fingerprint): OtpSendResult
    {
        // Demo/QA addresses take a fixed code and get no message; see
        // config('otp.test_addresses'). Everything below still applies to
        // them except the two rules that exist only because delivery is
        // real: the per-address send cap and the wrong-entry block.
        $isTestAddress = EmailAddress::isTestAddress($email);

        if ($isTestAddress) {
            // Asking for a fresh code clears the block, so a fumbled entry
            // cannot strand the one account meant to be reachable on demand.
            // Guessing stays bounded by the requester throttle below.
            $this->clearBlock($email);
        } elseif (($blockedFor = $this->blockSecondsRemaining($email)) > 0) {
            // A blocked address gets no new codes — the block would make them
            // unverifiable anyway, and sending would leak "this address is known".
            return OtpSendResult::blocked($blockedFor);
        }

        // Per-requester throttles: 1/min (matches the UI resend timer) and
        // 3/hour, keyed by fingerprint + IP.
        $requester = 'otp:send:requester:'.sha1($fingerprint.'|'.$ip);
        $limits = config('otp.limits');

        if (RateLimiter::tooManyAttempts($requester.':m', (int) $limits['per_requester_minute'])) {
            return OtpSendResult::rateLimited(RateLimiter::availableIn($requester.':m'));
        }
        if (RateLimiter::tooManyAttempts($requester.':h', (int) $limits['per_requester_hour'])) {
            return OtpSendResult::rateLimited(RateLimiter::availableIn($requester.':h'));
        }

        // Per-address cap: the initial send plus one resend per window, no
        // matter how many devices/IPs ask — stops flooding a victim's inbox.
        // A test address has no message to flood it with, and the cap would
        // end its second login of the day, so it is the one limit lifted there.
        $addressKey = 'otp:send:email:'.sha1($email);
        if (! $isTestAddress && RateLimiter::tooManyAttempts($addressKey, (int) $limits['per_address'])) {
            return OtpSendResult::rateLimited(RateLimiter::availableIn($addressKey));
        }

        // App-wide fuse, checked LAST so ordinary throttling doesn't consume
        // it: when tripped, code sending is off for everyone until the minute
        // window rolls — logged loudly, polite error to the user.
        if (RateLimiter::tooManyAttempts(self::GLOBAL_KEY, (int) $limits['global_per_minute'])) {
            Log::warning('[otp] global send fuse tripped; code sending temporarily disabled', [
                'limit_per_minute' => (int) $limits['global_per_minute'],
            ]);

            return OtpSendResult::unavailable();
        }

        RateLimiter::hit($requester.':m', 60);
        RateLimiter::hit($requester.':h', 3600);
        RateLimiter::hit(self::GLOBAL_KEY, 60);

        if (! $isTestAddress) {
            RateLimiter::hit($addressKey, (int) $limits['per_address_window_minutes'] * 60);
        }

        // One active code per address: a resend replaces the previous code,
        // so there is never a pile of live codes to guess against.
        OtpCode::query()->where('email', $email)->unconsumed()->delete();

        $code = $isTestAddress ? (string) config('otp.test_code') : $this->generateCode();
        $ttl = (int) config('otp.ttl_minutes');

        $otp = OtpCode::create([
            'email' => $email,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes($ttl),
        ]);

        // The bypass ends here: the row is a perfectly ordinary code, so TTL,
        // single-use and wrong-entry counting all behave as they always do —
        // there is simply nothing to deliver. Logged loudly, because a login
        // that skipped delivery must be visible, never a silent side door.
        if ($isTestAddress) {
            Log::warning('[otp] test-address bypass: the fixed code was issued and nothing was sent');

            return OtpSendResult::sent();
        }

        try {
            // Sent inline, not queued: the user is waiting on this screen for
            // the message, and a transport failure has to come back as the
            // honest "we could not send it" rather than a "sent" that a dead
            // queue job never delivers.
            Mail::to($email)->send(new LoginCode($code, $ttl));
        } catch (Throwable $e) {
            // Undelivered code = no active code (the row must not linger as a
            // guessable secret for a message that never arrived), and the
            // per-address allowance goes back: a failing mailer is not the
            // user's doing. The requester limits stay spent either way — a
            // failing transport must not be hammerable for free.
            $otp->delete();
            RateLimiter::decrement($addressKey);

            Log::error('[otp] delivery failed', [
                'reason' => $e->getMessage(),
            ]);

            return OtpSendResult::failed();
        }

        return OtpSendResult::sent();
    }

    /**
     * Check $code against the address's active code. Wrong entries accumulate
     * on the code row; the max_failed_attempts-th miss blocks the address for
     * block_minutes. A correct code is consumed atomically — exactly once.
     */
    public function verify(string $email, string $code): OtpVerifyResult
    {
        if (($blockedFor = $this->blockSecondsRemaining($email)) > 0) {
            return OtpVerifyResult::blocked($blockedFor);
        }

        /** @var OtpCode|null $otp */
        $otp = OtpCode::query()
            ->where('email', $email)
            ->unconsumed()
            ->latest('id')
            ->first();

        // No active code (never sent, already used, or replaced) or TTL ran
        // out: either way the honest answer is "get a new code".
        if ($otp === null || $otp->isExpired()) {
            return OtpVerifyResult::expired();
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');

            $max = (int) config('otp.max_failed_attempts');

            if ($otp->attempts >= $max) {
                // Spend the code and block the address — the design's "Too
                // many attempts, try again in 3 hours" state.
                $otp->update(['consumed_at' => now()]);

                return OtpVerifyResult::blocked($this->block($email));
            }

            return OtpVerifyResult::invalid($max - $otp->attempts);
        }

        // Single-use: the conditional update is the atomic claim — of two
        // racing verifies with the correct code, exactly one sees 1 here.
        $claimed = OtpCode::whereKey($otp->id)
            ->whereNull('consumed_at')
            ->update(['consumed_at' => now()]);

        if ($claimed === 0) {
            return OtpVerifyResult::expired();
        }

        return OtpVerifyResult::verified();
    }

    /** Seconds until $email unblocks; 0 when it is not blocked. */
    public function blockSecondsRemaining(string $email): int
    {
        $until = (int) Cache::get($this->blockKey($email), 0);

        return max(0, $until - now()->getTimestamp());
    }

    /** Block $email for the configured window; returns the block length in seconds. */
    private function block(string $email): int
    {
        $seconds = (int) config('otp.block_minutes') * 60;

        Cache::put($this->blockKey($email), now()->getTimestamp() + $seconds, $seconds);

        return $seconds;
    }

    /** Lift an address's block early (test addresses only — see send()). */
    private function clearBlock(string $email): void
    {
        Cache::forget($this->blockKey($email));
    }

    private function blockKey(string $email): string
    {
        return 'otp:block:'.sha1($email);
    }

    /** A zero-padded numeric code of the configured length (e.g. "042519"). */
    private function generateCode(): string
    {
        $length = max(4, (int) config('otp.code_length'));

        return str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
