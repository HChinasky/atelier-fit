<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the whole site out of search engines. public/robots.txt asks crawlers
 * not to fetch anything; this header covers what is fetched anyway (a link
 * shared somewhere, a crawler that ignores robots.txt) and every response the
 * app renders — pages, JSON, redirects and error pages alike.
 *
 * One exception: the public data-deletion instructions. Meta's app settings
 * refuse that URL while the page carries noindex, and a page that only says
 * how to have your data deleted has nothing to hide from a search engine.
 */
class NoIndex
{
    /** Paths that outside validators must be able to read as ordinary pages. */
    private const INDEXABLE = ['data-deletion'];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is(...self::INDEXABLE)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
