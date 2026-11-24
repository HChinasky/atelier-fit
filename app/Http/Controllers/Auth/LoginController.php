<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The landing page ("/") is the sign-in page: passwordless (a code by e-mail,
 * Google or Apple), the single auth entry point for the whole site (guests bounced off /account land
 * here — see bootstrap/app.php).
 *
 * The route itself is NOT behind the membership feature flag — "/" must answer
 * on every deploy — so the flag is judged here instead: with membership off
 * there is nothing to sign in to, so the only landing left is the try-on page,
 * and if that is closed too the site has no public surface at all (404).
 */
class LoginController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        if (! config('features.membership', false)) {
            abort_unless(config('features.fitting_room', false), 404);

            return redirect()->route('fitting-room');
        }

        if ($request->user()) {
            return redirect()->route('membership.account.dashboard');
        }

        // The page renders the email/otp steps inline, so the overlay copies of
        // them (partials/modals) must not be included by the layout.
        return view('auth.login', ['withoutOverlays' => true]);
    }
}
