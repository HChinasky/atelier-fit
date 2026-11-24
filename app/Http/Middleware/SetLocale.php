<?php

namespace App\Http\Middleware;

use App\Services\Locale\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the request in the visitor's language (see Locales for the order).
 * ?lang=<code> on any page is a choice, the same as the switcher: it is
 * remembered, so a shared link in English keeps the reader in English.
 */
class SetLocale
{
    /** Request attribute: the language is set (App\Exceptions\LocalizedErrors reads it). */
    public const RESOLVED = 'locale_resolved';

    public function __construct(private readonly Locales $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        $asked = $request->query('lang');
        if (Locales::supports($asked)) {
            $this->locales->remember($request, $asked);
        }

        app()->setLocale($this->locales->resolve($request));
        $request->attributes->set(self::RESOLVED, true);

        $response = $next($request);

        // Pages differ by language: a shared cache must not hand one visitor's to another.
        $response->headers->set('Content-Language', app()->getLocale());
        $vary = $response->headers->get('Vary');
        $response->headers->set('Vary', $vary ? $vary.', Accept-Language' : 'Accept-Language');

        return $response;
    }
}
