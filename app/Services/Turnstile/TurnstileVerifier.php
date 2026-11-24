<?php

namespace App\Services\Turnstile;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Server-side Cloudflare Turnstile check, run BEFORE an OTP send so bots pay
 * a challenge before they can make us send a message.
 *
 * Off by design in two cases (verify() then always passes):
 * - the 'local' environment — the dev loop and the Б4 sandbox test run
 *   without Cloudflare keys;
 * - no secret configured — the widget can't have rendered, so there is no
 *   token to check (deploys stay decoupled from Cloudflare setup).
 *
 * When enabled it FAILS CLOSED: a missing/invalid token or an unreachable
 * siteverify endpoint refuses the request. OTP sending is a paid, abusable
 * action — degrading open here would switch the bot protection off exactly
 * when it is being stressed.
 */
class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function enabled(): bool
    {
        return (string) config('services.turnstile.secret') !== ''
            && ! app()->environment('local');
    }

    public function verify(?string $token, ?string $ip): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        if ($token === null || $token === '') {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout((int) config('services.turnstile.timeout', 5))
                ->post(self::VERIFY_URL, [
                    'secret' => config('services.turnstile.secret'),
                    'response' => $token,
                    'remoteip' => $ip,
                ]);

            return $response->successful() && $response->json('success') === true;
        } catch (Throwable $e) {
            Log::warning('[turnstile] siteverify unreachable; failing closed', [
                'reason' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
