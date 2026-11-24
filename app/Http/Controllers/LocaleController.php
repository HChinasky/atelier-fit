<?php

namespace App\Http\Controllers;

use App\Services\Locale\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** The language switcher: keep the choice, go back to the page it was made on. */
class LocaleController extends Controller
{
    public function __invoke(Request $request, Locales $locales, string $locale): RedirectResponse
    {
        abort_unless(Locales::supports($locale), 404);

        $locales->remember($request, $locale);

        return redirect()->to($this->back($request));
    }

    /** Only back onto this site: a Referer from elsewhere must not turn the switch into an open redirect. */
    private function back(Request $request): string
    {
        $previous = (string) $request->headers->get('referer', '');
        $host = parse_url($previous, PHP_URL_HOST);

        if ($previous !== '' && $host === $request->getHost()) {
            return $previous;
        }

        return route('login');
    }
}
