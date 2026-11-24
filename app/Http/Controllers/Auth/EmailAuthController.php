<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SendCodeRequest;
use App\Http\Requests\Auth\VerifyCodeRequest;
use App\Services\Auth\MemberAuthenticator;
use App\Services\Otp\EmailAddress;
use App\Services\Otp\OtpSendStatus;
use App\Services\Otp\OtpService;
use App\Services\Otp\OtpVerifyStatus;
use App\Services\Turnstile\TurnstileVerifier;
use App\Services\Waitlist\WaitlistSignup;
use Illuminate\Http\JsonResponse;

/**
 * Passwordless e-mail login: thin JSON endpoints over the OTP core, consumed
 * by the email/otp steps of the sign-in page and the gate overlay. Response
 * `status` strings are a UI contract — the front-end switches its drawn
 * states on them.
 */
class EmailAuthController extends Controller
{
    /**
     * POST /auth/email/send — Turnstile gate, then issue + mail a code.
     * Resend is the same endpoint (the OTP core replaces the active code).
     */
    public function send(
        SendCodeRequest $request,
        OtpService $otp,
        TurnstileVerifier $turnstile,
        WaitlistSignup $waitlist,
    ): JsonResponse {
        if (! $turnstile->verify($request->validated('turnstile_token'), $request->ip())) {
            return response()->json([
                'status' => 'turnstile_failed',
                'message' => __('login.server.not_human'),
            ], 403);
        }

        $email = $request->email();

        // Pre-launch: the address is kept and the visitor is told we are not
        // open. Judged AFTER validation and Turnstile, so the list holds real
        // addresses left by people; and not for a test address, which is how
        // the owner gets in behind the curtain.
        if (config('features.coming_soon') && ! EmailAddress::isTestAddress($email)) {
            $waitlist->record($email, $request);
            $request->session()->put(ComingSoonController::SESSION_EMAIL, $email);

            return response()->json([
                'status' => 'waitlisted',
                'redirect' => route('coming-soon'),
            ]);
        }

        $result = $otp->send(
            $email,
            (string) $request->ip(),
            (string) $request->validated('fingerprint'),
        );

        return match ($result->status) {
            OtpSendStatus::Sent => response()->json([
                'status' => 'sent',
                // The UI resend timer; mirrors the 1/min requester limit.
                'resend_in' => 60,
            ]),
            OtpSendStatus::RateLimited => response()->json([
                'status' => 'rate_limited',
                'retry_after' => $result->retryAfterSeconds,
                'message' => __('login.server.wait'),
            ], 429),
            OtpSendStatus::Blocked => response()->json([
                'status' => 'blocked',
                'retry_after' => $result->retryAfterSeconds,
            ], 429),
            OtpSendStatus::Unavailable => response()->json([
                'status' => 'unavailable',
                'message' => __('login.server.unavailable'),
            ], 503),
            // 500, NOT 502/504: Cloudflare replaces an origin 502/504 with its
            // own error page, so this JSON (and its message) would never reach
            // the browser — the UI then shows a generic "Something went wrong"
            // instead of the honest delivery-failure text. A plain 500 passes
            // through the proxy untouched.
            OtpSendStatus::Failed => response()->json([
                'status' => 'failed',
                'message' => __('login.server.send_failed'),
            ], 500),
        };
    }

    /**
     * POST /auth/email/verify — check the code; on success log in (creating
     * the account on first login) and hand the UI its landing URL.
     */
    public function verify(VerifyCodeRequest $request, OtpService $otp, MemberAuthenticator $authenticator): JsonResponse
    {
        $result = $otp->verify($request->email(), (string) $request->validated('code'));

        if ($result->isVerified()) {
            $user = $authenticator->login($request->email());

            // A fresh session id after privilege change (session fixation).
            $request->session()->regenerate();

            return response()->json([
                'status' => 'ok',
                'redirect' => LandingPage::for($user),
            ]);
        }

        return match ($result->status) {
            OtpVerifyStatus::Invalid => response()->json([
                'status' => 'invalid',
                'attempts_left' => $result->attemptsLeft,
            ], 422),
            OtpVerifyStatus::Expired => response()->json([
                'status' => 'expired',
                'message' => __('login.server.expired'),
            ], 422),
            OtpVerifyStatus::Blocked => response()->json([
                'status' => 'blocked',
                'retry_after' => $result->retryAfterSeconds,
            ], 429),
            // @codeCoverageIgnoreStart — Verified is handled above.
            OtpVerifyStatus::Verified => response()->json(['status' => 'ok']),
            // @codeCoverageIgnoreEnd
        };
    }
}
