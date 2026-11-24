<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Waitlist\WaitlistSignup;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * GET /coming-soon — where the sign-in field leads while the atelier is not
 * open yet (FEATURE_COMING_SOON).
 *
 * Says two things and no more: we are not open, and the address just left is
 * kept for the day we are. The address is shown back masked, from the session
 * the sign-in step wrote it to; opened cold, the page still makes sense and
 * points back to the field.
 */
class ComingSoonController extends Controller
{
    public const SESSION_EMAIL = 'waitlist_email';

    public function show(Request $request): View
    {
        $email = $request->session()->get(self::SESSION_EMAIL);

        return view('auth.coming-soon', [
            'withoutOverlays' => true,
            'maskedEmail' => is_string($email) && $email !== '' ? WaitlistSignup::mask($email) : null,
        ]);
    }
}
