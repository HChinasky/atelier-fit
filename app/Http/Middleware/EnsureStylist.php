<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route group to stylists — and to administrators, who count as
 * stylists (see {@see User::isStylist()}).
 *
 * 404 rather than 403, for exactly the reason {@see EnsureAdmin} gives: 403
 * confirms the area exists and, by which paths answer which, maps its shape.
 * One story everywhere — if you may not have it, it is not there.
 *
 * Usage: Route::middleware(['feature:color_analysis', 'auth', 'stylist'])->...
 */
class EnsureStylist
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user !== null && $user->isStylist(), 404);

        return $next($request);
    }
}
