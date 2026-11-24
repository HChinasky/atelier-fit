<?php

namespace App\Http\Controllers\Auth;

use App\Models\User;

/**
 * Where a just-signed-in member lands. The style quiz is offered once: until
 * it has been answered or skipped, signing in lands on it instead of the
 * dashboard. Shared by every way in (code by e-mail, Google, Apple).
 */
final class LandingPage
{
    public static function for(User $user): string
    {
        $offered = $user->styleProfile?->wasOffered() ?? false;

        return route($offered ? 'membership.account.dashboard' : 'membership.account.onboarding');
    }
}
