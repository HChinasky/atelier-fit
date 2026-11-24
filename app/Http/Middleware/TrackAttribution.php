<?php

namespace App\Http\Middleware;

use App\Services\Analytics\AnalyticsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Campaign attribution (Этап A1). On every public GET page view, campaign
 * parameters (utm_* and ref) are folded into the EXISTING session:
 *
 *  - first_touch is written once and never overwritten — the campaign that
 *    brought this visitor here first;
 *  - last_touch is overwritten every time campaign parameters appear;
 *  - landing_path and the referrer HOST (never its query string) ride along
 *    with the first touch.
 *
 * The values are read back exactly once, when a lead is created (they land in
 * the leads table for funnel attribution). They are never shown to the
 * visitor, never sent to any vendor as-is, and never written to plain logs —
 * the session store and the leads table are their only homes. The same
 * middleware fires landing_viewed (once per session), since it already owns
 * "this is a page view with campaign context".
 */
class TrackAttribution
{
    private const UTM_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'ref'];

    public const SESSION_FIRST = 'attribution_first_touch';

    public const SESSION_LAST = 'attribution_last_touch';

    private const SESSION_LANDED = 'analytics_landing_viewed';

    public function __construct(private readonly AnalyticsService $analytics) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && $request->hasSession() && ! $this->excluded($request)) {
            $this->record($request);
        }

        return $next($request);
    }

    private function record(Request $request): void
    {
        $session = $request->session();
        $touch = $this->touchFrom($request);

        if ($touch !== []) {
            $session->put(self::SESSION_LAST, $touch);
        }

        if (! $session->has(self::SESSION_FIRST)) {
            $session->put(self::SESSION_FIRST, array_merge($touch, [
                'landing_path' => '/'.ltrim($request->path(), '/'),
                'referrer_host' => $this->referrerHost($request),
            ]));
        }

        if (! $session->has(self::SESSION_LANDED)) {
            $session->put(self::SESSION_LANDED, true);

            $this->analytics->capture('landing_viewed', array_merge($touch, [
                'landing_path' => '/'.ltrim($request->path(), '/'),
            ]));
        }
    }

    /**
     * The campaign parameters present on THIS request, scalar and truncated —
     * a query string is attacker-controlled input headed for the database.
     *
     * @return array<string, string>
     */
    private function touchFrom(Request $request): array
    {
        $touch = [];

        foreach (self::UTM_KEYS as $key) {
            $value = $request->query($key);

            if (is_string($value) && trim($value) !== '') {
                $touch[$key] = mb_substr(trim($value), 0, 255);
            }
        }

        return $touch;
    }

    /** The referring site's host only — never its path or query. */
    private function referrerHost(Request $request): ?string
    {
        $referer = (string) $request->headers->get('referer', '');

        if ($referer === '') {
            return null;
        }

        $host = parse_url($referer, PHP_URL_HOST);

        if (! is_string($host) || $host === '' || $host === $request->getHost()) {
            return null; // self-referrals carry no attribution signal
        }

        return mb_substr($host, 0, 255);
    }

    /** Infrastructure endpoints that must not mint sessions or events. */
    private function excluded(Request $request): bool
    {
        return $request->is('health', 'up');
    }
}
