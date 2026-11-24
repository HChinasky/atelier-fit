<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route group to administrators.
 *
 * Deliberately paired with, not a replacement for, the feature flag. The flag
 * decides whether the routes exist at all; this decides who gets past them once
 * they do. Guests are sent to sign in by the auth middleware before this runs.
 *
 * A signed-in member who is not an administrator gets **404, not 403**. 403 is
 * the more literal answer, and it is the wrong one here: it confirms that
 * /admin/color exists, that there is an administrator tier, and — by which
 * paths answer 403 rather than 404 — the shape of the whole area. A member has
 * no business learning any of that from probing, and the flag already answers
 * 404 for the same reason, so this keeps one story: if you may not have it, it
 * is not there.
 *
 * Usage: Route::middleware(['feature:color_analysis', 'auth', 'admin'])->...
 */
class EnsureAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user !== null && $user->isAdmin(), 404);

        return $next($request);
    }
}
