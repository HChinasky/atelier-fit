<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds a route group to one language whatever the visitor picked: the
 * internal tools (/admin/color, /stylist/color) are written in English, and
 * a season name in another language inside them would only be a mix.
 * Runs after SetLocale, so it has the last word. Usage: `locale:en`.
 */
class PinLocale
{
    public function handle(Request $request, Closure $next, string $locale): Response
    {
        app()->setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', $locale);

        return $response;
    }
}
