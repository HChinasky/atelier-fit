<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use App\Services\Auth\MemberAuthenticator;
use App\Services\Auth\SocialProviders;
use App\Services\Otp\EmailAddress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Sign in with Google or Apple (Laravel Socialite).
 *
 * The provider proves the e-mail; MemberAuthenticator does the rest, exactly
 * as it does after an e-mailed code. The provider's subject id is remembered
 * in social_accounts so a later sign-in finds the same account even if the
 * address at the provider changes, and a first sign-in with an address the
 * app already knows (from a code login, or the other provider) attaches to
 * that account instead of making a second one — both Google and Apple only
 * ever hand out addresses they have verified.
 */
class SocialAuthController extends Controller
{
    /** GET /auth/{provider}/redirect */
    public function redirect(string $provider): RedirectResponse
    {
        abort_unless(SocialProviders::isEnabled($provider), Response::HTTP_NOT_FOUND);

        return $this->driver($provider)->redirect();
    }

    /** GET|POST /auth/{provider}/callback */
    public function callback(Request $request, string $provider, MemberAuthenticator $authenticator): RedirectResponse
    {
        abort_unless(SocialProviders::isEnabled($provider), Response::HTTP_NOT_FOUND);

        // The person pressed "Cancel" at the provider: back to the sign-in
        // page, no error — it is not one.
        if ($request->filled('error')) {
            return redirect()->route('login');
        }

        try {
            $identity = $this->driver($provider)->user();
        } catch (Throwable $e) {
            // Expired state, a replayed callback, the provider down: the user
            // can only start again, so say that — never the internals.
            Log::warning('[auth] social sign-in failed', [
                'provider' => $provider,
                'reason' => $e->getMessage(),
            ]);

            return redirect()->route('login')->with('auth_error', __('login.server.social_failed'));
        }

        $user = DB::transaction(function () use ($provider, $identity, $authenticator) {
            $link = SocialAccount::query()
                ->where('provider', $provider)
                ->where('provider_id', (string) $identity->getId())
                ->first();

            // Known identity: its account, whatever the provider says the
            // address is today.
            if ($link !== null) {
                return $authenticator->login($link->user->email, $identity->getName());
            }

            $email = $identity->getEmail();
            if (! is_string($email) || trim($email) === '') {
                return null;
            }

            $user = $authenticator->login($email, $identity->getName());

            SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_id' => (string) $identity->getId(),
                'email' => EmailAddress::normalize($email),
            ]);

            return $user;
        });

        if ($user === null) {
            // A provider that did not share an address leaves nothing to key
            // an account by. Apple does this only when the first authorisation
            // was interrupted before we stored the link.
            return redirect()->route('login')->with('auth_error', __('login.server.social_no_email', ['provider' => ucfirst($provider)]));
        }

        $request->session()->regenerate();

        return redirect()->to(LandingPage::for($user));
    }

    /**
     * Apple posts the callback cross-site (response_mode=form_post), so the
     * Lax session cookie does not travel with it and a session-bound state
     * check would fail every time. Its flow therefore runs stateless, with
     * the nonce carried in the provider's own encrypted cookie instead
     * (socialiteproviders/apple cookieNonce). Google redirects with a GET
     * and keeps the ordinary session-bound state.
     */
    private function driver(string $provider): Provider
    {
        $driver = Socialite::driver($provider);

        if ($provider === SocialAccount::PROVIDER_APPLE) {
            $driver = $driver->stateless()->cookieNonce();
        }

        return $driver;
    }
}
