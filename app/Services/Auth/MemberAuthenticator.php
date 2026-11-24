<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Credits\CreditService;
use App\Services\Locale\Locales;
use App\Services\Otp\EmailAddress;
use Illuminate\Support\Facades\Auth;

/**
 * Passwordless login by a verified e-mail address. Called ONLY after the
 * address has been proven — the OTP core confirmed a code sent to it, or
 * Google/Apple vouched for it — this class never sees or checks proofs.
 *
 * First-ever login auto-creates the user from nothing but the address and
 * hands out the welcome credits. The grant itself is once per ADDRESS ever
 * (CreditService + welcome_grants), so a deleted-then-recreated account with
 * the same address logs in fine but gets nothing again.
 */
class MemberAuthenticator
{
    public function __construct(
        private readonly CreditService $credits,
        private readonly Locales $locales,
    ) {}

    /**
     * Log the owner of $email in, creating the account on first login.
     * $name is kept only when the account has none yet (Apple sends it once,
     * on the first authorisation, and never again).
     */
    public function login(string $email, ?string $name = null): User
    {
        $email = EmailAddress::normalize($email);

        $user = User::firstOrCreate(['email' => $email]);

        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();

            $this->credits->grantWelcome($user, $email);
        }

        if ($user->name === null && $name !== null && trim($name) !== '') {
            $user->forceFill(['name' => trim($name)])->save();
        }

        // Activity stamp for the account-retention rule (config/privacy.php): an
        // account is only ever deleted for INACTIVITY, so every sign-in has to
        // push that clock forward.
        $user->forceFill(['last_active_at' => now()])->save();

        Auth::login($user, remember: true);

        $this->locales->syncAtLogin($user);

        return $user;
    }
}
