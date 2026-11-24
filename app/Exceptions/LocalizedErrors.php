<?php

namespace App\Exceptions;

use App\Http\Middleware\SetLocale;
use App\Services\Locale\Locales;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Errors the framework answers by itself, in the visitor's language.
 *
 * The front-end shows a JSON error's "message" as it is, and Laravel's own
 * ("Unauthenticated.", "Too Many Attempts.", "CSRF token mismatch.") are
 * English — a 404 even names the model ("No query results for model
 * [App\Models\Product]"). Here each status gets one worded message from
 * lang/<l>/errors.php instead. Messages the app words itself (validation,
 * the generation gate, the sign-in answers) are responses, not exceptions,
 * and are left alone.
 */
final class LocalizedErrors
{
    /** HTTP status → message key. */
    private const MESSAGES = [
        401 => 'errors.unauthenticated',
        403 => 'errors.forbidden',
        404 => 'errors.not_found',
        405 => 'errors.method_not_allowed',
        419 => 'errors.page_expired',
        429 => 'errors.too_many_requests',
        500 => 'errors.server_error',
        503 => 'errors.unavailable',
    ];

    public static function register(Exceptions $exceptions): void
    {
        // An unknown URL or a stale CSRF token is refused before SetLocale has
        // run: pick the language here, for the JSON message and the error page.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->attributes->has(SetLocale::RESOLVED)) {
                app()->setLocale(app(Locales::class)->resolve($request));
            }

            return null;
        });

        $exceptions->render(fn (AuthenticationException $e, Request $request) => $request->expectsJson() ? self::json(401) : null);

        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->expectsJson() || ! isset(self::MESSAGES[$e->getStatusCode()])) {
                return null;
            }

            return self::json($e->getStatusCode(), $e->getHeaders());
        });

        // Anything else is a 500. In debug mode the framework's own page, with the trace, stays.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->expectsJson() || config('app.debug')
                || $e instanceof ValidationException || $e instanceof HttpResponseException) {
                return null;
            }

            return self::json(500);
        });
    }

    /** @param  array<string, string>  $headers */
    private static function json(int $status, array $headers = []): JsonResponse
    {
        return response()->json(['message' => __(self::MESSAGES[$status])], $status, $headers);
    }
}
