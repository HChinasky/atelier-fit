<?php

namespace App\Services\Locale;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

/**
 * Which language the interface speaks for this request, and remembering a
 * visitor's choice of it.
 *
 * A choice made on purpose (the switcher, or ?lang= on a link) wins over
 * everything and is kept three ways: in the session for this visit, in a
 * cookie so a guest finds it again next month, and on the account so a member
 * finds it on another device. Without one, the browser's Accept-Language
 * picks among the supported languages, and APP_LOCALE settles the rest.
 */
class Locales
{
    public const SESSION_KEY = 'locale';

    public const COOKIE = 'locale';

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys((array) config('locales.supported', []));
    }

    public static function supports(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, self::codes(), true);
    }

    /** @return array<string, array{name: string, short: string, dir: string}> */
    public static function all(): array
    {
        return (array) config('locales.supported', []);
    }

    public static function direction(?string $locale = null): string
    {
        return self::all()[$locale ?? app()->getLocale()]['dir'] ?? 'ltr';
    }

    /** The configured default, or the first supported language when it is not one of them. */
    public static function fallback(): string
    {
        $default = config('app.locale');

        return self::supports($default) ? $default : (self::codes()[0] ?? 'en');
    }

    public function resolve(Request $request): string
    {
        $chosen = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;
        if (self::supports($chosen)) {
            return $chosen;
        }

        $saved = $request->user()?->locale;
        if (self::supports($saved)) {
            return $saved;
        }

        $cookie = $request->cookie(self::COOKIE);
        if (self::supports($cookie)) {
            return $cookie;
        }

        // getLanguages() turns "uk-UA" into "uk_UA": compare the language part only.
        foreach ($request->getLanguages() as $language) {
            $code = strtolower((string) strtok($language, '_-'));
            if (self::supports($code)) {
                return $code;
            }
        }

        return self::fallback();
    }

    /** Keep a choice the visitor made on purpose. */
    public function remember(Request $request, string $locale): void
    {
        if (! self::supports($locale)) {
            return;
        }

        if ($request->hasSession()) {
            $request->session()->put(self::SESSION_KEY, $locale);
        }

        Cookie::queue(self::COOKIE, $locale, (int) config('locales.cookie_minutes'));

        $user = $request->user();
        if ($user instanceof User && $user->locale !== $locale) {
            $user->forceFill(['locale' => $locale])->save();
        }
    }

    /**
     * At sign-in: a choice made on this device before signing in goes onto
     * the account; otherwise the account's own choice takes over the session.
     */
    public function syncAtLogin(User $user): void
    {
        $request = request();
        $chosen = $request->hasSession() ? $request->session()->get(self::SESSION_KEY) : null;

        if (self::supports($chosen)) {
            if ($user->locale !== $chosen) {
                $user->forceFill(['locale' => $chosen])->save();
            }

            return;
        }

        if (self::supports($user->locale)) {
            if ($request->hasSession()) {
                $request->session()->put(self::SESSION_KEY, $user->locale);
            }
            app()->setLocale($user->locale);
        }
    }
}
