<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a route group behind a config('features.*') flag. A disabled feature
 * is indistinguishable from a missing page (404) — nothing hints the routes
 * exist, so unfinished areas can ship dark on production.
 *
 * Usage: Route::middleware('feature:membership')->...
 *
 * Several flags separated by "|" mean ANY of them opens the route:
 * Route::middleware('feature:fitting_room|membership'). That is for a route
 * two surfaces share — the try-on endpoints serve both the internal page and
 * the member account, and closing the internal page must not close the
 * account's try-on with it.
 */
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $features): Response
    {
        $enabled = collect(explode('|', $features))
            ->map(fn (string $feature) => trim($feature))
            ->filter()
            ->contains(fn (string $feature) => (bool) config("features.{$feature}", false));

        abort_unless($enabled, 404);

        return $next($request);
    }
}
