<?php

use App\Exceptions\LocalizedErrors;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureFeatureEnabled;
use App\Http\Middleware\EnsureStylist;
use App\Http\Middleware\NoIndex;
use App\Http\Middleware\PinLocale;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\TrackAttribution;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\Middleware\StartSession;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'feature' => EnsureFeatureEnabled::class,
            'admin' => EnsureAdmin::class,
            'stylist' => EnsureStylist::class,
            'locale' => PinLocale::class,
        ]);

        // The site is closed to search engines (see also public/robots.txt).
        $middleware->append(NoIndex::class);

        // Campaign attribution (utm_* → session first/last touch) + the
        // once-per-session landing_viewed event, on public web pages only.
        $middleware->web(append: [TrackAttribution::class]);

        // The interface language (config/locales.php, App\Services\Locale\Locales).
        $middleware->web(append: [SetLocale::class]);

        // The try-on API is called from our own page by a browser that already
        // carries the web session cookie. Reading that EXISTING session (no new
        // cookie, no CSRF layer — the api group still has none) is what lets
        // server-side analytics keep one pseudonymous id across the funnel and
        // lets a lead inherit the session's campaign attribution.
        $middleware->api(prepend: [
            EncryptCookies::class,
            StartSession::class,
        ]);
        // JSON messages (validation, errors) speak the page's language too.
        $middleware->api(append: [SetLocale::class]);

        // The feature flag must be judged BEFORE auth: a disabled area is a
        // plain 404 for everyone — never an auth redirect that reveals the
        // routes exist. Laravel reorders route middleware by its priority
        // list, so the flag check is explicitly placed above auth there. The
        // list names auth by its CONTRACT (AuthenticatesRequests) — anchoring
        // to the concrete Authenticate class would not match and the prepend
        // would silently land at the end of the list.
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: EnsureFeatureEnabled::class,
        );

        // Apple delivers its sign-in callback as a cross-site POST
        // (response_mode=form_post), which carries no CSRF token of ours.
        // The request is authenticated by the signed identity token and the
        // nonce cookie instead (see Auth\SocialAuthController).
        $middleware->validateCsrfTokens(except: ['auth/apple/callback']);

        // Auth is passwordless (e-mail code, Google, Apple) and its single
        // entry point is the landing page "/" (see Auth\LoginController), so
        // guests hitting a protected page land there. Only membership routes carry the auth
        // middleware, so this fires only while the feature flag is on.
        $middleware->redirectGuestsTo(fn () => route('login'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The framework's own error answers, in the visitor's language.
        LocalizedErrors::register($exceptions);
    })->create();
