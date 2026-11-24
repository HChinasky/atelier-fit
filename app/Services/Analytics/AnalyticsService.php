<?php

namespace App\Services\Analytics;

use App\Jobs\SendAnalyticsEventJob;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Server-side product analytics (PostHog EU). The single entry point is
 * capture(): it sanitizes the properties, stamps the shared context and queues
 * one job per event on a dedicated queue. Analytics NEVER runs inline with the
 * funnel: a PostHog outage, a bad key or a full queue must not slow down or
 * break a try-on, so every failure path here is a silent drop (at most a
 * debug-level note, never a user-facing error, never a retry storm).
 *
 * Identity model (pseudonymous by construction):
 *  - before a contact exists: an anonymous UUID stored in the EXISTING Laravel
 *    session (no new cookie is created for this);
 *  - at contact_submitted: the anonymous id is aliased to an HMAC-SHA256 of
 *    the lead id under an APP_KEY-derived key, and that hash becomes the
 *    distinct id from then on (jobs recompute it from lead_id alone);
 *  - the phone number, email, name and IP are NEVER sent to PostHog, and
 *    $geoip_disable is set on every event so PostHog does not derive location
 *    from the transport IP either.
 */
class AnalyticsService
{
    /** Session keys for the identity described above. */
    private const SESSION_ANON = 'analytics_anon_id';

    private const SESSION_DISTINCT = 'analytics_distinct_id';

    /**
     * Property KEYS that must never reach PostHog, whatever the call site does.
     * Photo/result URLs are excluded by the value filter (no URL-shaped string
     * survives sanitizeProps), this list stops direct identifiers.
     */
    private const FORBIDDEN_KEYS = [
        'ip', 'contact', 'email', 'phone', 'name', 'photo_key', 'client_photo',
        'photo', 'result_urls', 'url', 'error', 'message', 'consent', 'consent_id',
    ];

    /**
     * Queue one product event. $distinctId defaults to the current session's
     * identity (anonymous UUID, or the lead hash once aliased); jobs pass the
     * lead hash explicitly. $ip feeds ONLY the is_internal flag — it is never
     * sent. No-op while analytics is disabled.
     *
     * @param  array<string, mixed>  $props
     */
    public function capture(string $event, array $props = [], ?string $distinctId = null, ?string $ip = null): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $request = $this->currentRequest();

            $payload = [
                'event' => $event,
                'distinct_id' => $distinctId ?? $this->distinctId(),
                'properties' => array_merge(
                    $this->sanitizeProps($props),
                    $this->commonProps($request, $ip),
                ),
                'timestamp' => now()->toIso8601String(),
            ];

            SendAnalyticsEventJob::dispatch($payload)
                ->onQueue((string) config('analytics.queue'));
        } catch (Throwable $e) {
            // Analytics must never break the funnel — swallow everything.
        }
    }

    /**
     * Link the current anonymous session id to the lead's pseudonymous id
     * (called once, at contact_submitted). From here on the session captures
     * under the lead hash, and queue jobs — which recompute the same hash from
     * lead_id — land on the same PostHog person.
     */
    public function aliasLead(Lead $lead): void
    {
        if (! $this->enabled()) {
            return;
        }

        try {
            $anonId = $this->distinctId();
            $leadId = $this->leadDistinctId($lead->id);

            if ($anonId !== $leadId) {
                SendAnalyticsEventJob::dispatch([
                    'event' => '$create_alias',
                    'distinct_id' => $anonId,
                    'properties' => [
                        'distinct_id' => $anonId,
                        'alias' => $leadId,
                        '$geoip_disable' => true,
                    ],
                    'timestamp' => now()->toIso8601String(),
                ])->onQueue((string) config('analytics.queue'));
            }

            $this->rememberDistinctId($leadId);
        } catch (Throwable $e) {
            // Same rule: never let identity plumbing break the lead capture.
        }
    }

    /**
     * The pseudonymous distinct id for a lead: HMAC-SHA256 of the lead id under
     * an APP_KEY-derived key. Deterministic (jobs and requests agree without
     * shared state) and infeasible to reverse to a lead id without APP_KEY.
     */
    public function leadDistinctId(int|string $leadId): string
    {
        return hash_hmac('sha256', (string) $leadId, $this->hmacKey());
    }

    /**
     * The current session's identity: the lead hash once aliased, otherwise an
     * anonymous UUID minted into the EXISTING session. Without a session (a
     * bare API hit outside the page flow) a throwaway UUID is used — the event
     * still lands, it just cannot be stitched to a funnel.
     */
    public function distinctId(): string
    {
        $request = $this->currentRequest();

        if ($request === null || ! $request->hasSession()) {
            return (string) Str::uuid();
        }

        $session = $request->session();

        $known = $session->get(self::SESSION_DISTINCT);
        if (is_string($known) && $known !== '') {
            return $known;
        }

        $anon = $session->get(self::SESSION_ANON);
        if (! is_string($anon) || $anon === '') {
            $anon = (string) Str::uuid();
            $session->put(self::SESSION_ANON, $anon);
        }

        return $anon;
    }

    /**
     * Delete a person (and their events) from PostHog by distinct id — the
     * erasure counterpart of capture, called by app:delete-user-data. Runs
     * synchronously (the operator is watching), is idempotent (an unknown
     * distinct id is a clean no-op) and NEVER throws: a PostHog hiccup must not
     * block the rest of an erasure. Returns true when the person is gone
     * (deleted or never existed).
     */
    public function deletePerson(string $distinctId): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        $projectId = (string) config('analytics.posthog.project_id');
        $personalKey = (string) config('analytics.posthog.personal_key');

        if ($projectId === '' || $personalKey === '') {
            return false; // not configured — the operator is told by the command
        }

        $base = (string) config('analytics.posthog.host');
        $timeout = (int) config('analytics.posthog.timeout', 5);

        try {
            $found = Http::withToken($personalKey)
                ->timeout($timeout)
                ->get($base.'/api/projects/'.$projectId.'/persons/', [
                    'distinct_id' => $distinctId,
                ]);

            if (! $found->successful()) {
                return false;
            }

            $results = (array) $found->json('results', []);

            if ($results === []) {
                return true; // nothing to delete — idempotent success
            }

            $ok = true;

            foreach ($results as $person) {
                $personId = $person['id'] ?? null;

                if ($personId === null) {
                    continue;
                }

                $deleted = Http::withToken($personalKey)
                    ->timeout($timeout)
                    ->delete($base.'/api/projects/'.$projectId.'/persons/'.$personId.'/', [
                        'delete_events' => 'true',
                    ]);

                $ok = $ok && ($deleted->successful() || $deleted->status() === 404);
            }

            return $ok;
        } catch (Throwable $e) {
            return false;
        }
    }

    public function enabled(): bool
    {
        return (bool) config('analytics.enabled');
    }

    /**
     * Whether this request comes from the team: IP allow-list or the explicit
     * marker cookie (set by hand in a team browser; the app never sets it).
     */
    public function isInternal(?Request $request, ?string $ip = null): bool
    {
        $internalIps = (array) config('analytics.internal_ips');
        $ip = $ip ?? $request?->ip();

        if ($ip !== null && in_array($ip, $internalIps, true)) {
            return true;
        }

        $cookie = (string) config('analytics.internal_cookie');

        return $cookie !== '' && $request !== null && $request->cookies->has($cookie);
    }

    /**
     * Shared context stamped on every event. Only coarse, non-identifying
     * values: device class, ISO country code (from Cloudflare's edge header —
     * never a lookup we run on the IP ourselves), the deployed revision and the
     * internal-traffic flag. $geoip_disable stops PostHog from deriving
     * location server-side; the IP itself is never in the payload.
     *
     * @return array<string, mixed>
     */
    private function commonProps(?Request $request, ?string $ip): array
    {
        $props = [
            'is_internal' => $this->isInternal($request, $ip),
            '$geoip_disable' => true,
        ];

        if ($request !== null) {
            $props['device_type'] = $this->deviceType((string) $request->userAgent());

            $country = strtoupper((string) $request->headers->get('CF-IPCountry', ''));
            if (preg_match('/^[A-Z]{2}$/', $country) === 1 && $country !== 'XX') {
                $props['country'] = $country;
            }
        }

        $version = (string) config('analytics.app_version');
        if ($version !== '') {
            $props['app_version'] = $version;
        }

        return $props;
    }

    /**
     * Strip anything that must not reach PostHog: forbidden keys, and any
     * string value shaped like a URL or an email address (photo/result links
     * and contacts can then never leak through a careless call site).
     *
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function sanitizeProps(array $props): array
    {
        $clean = [];

        foreach ($props as $key => $value) {
            if (in_array(strtolower((string) $key), self::FORBIDDEN_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitizeProps($value);

                continue;
            }

            if (is_string($value) && (str_contains($value, '://') || str_contains($value, '@'))) {
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    /** Coarse device class from the user agent — never the raw UA string. */
    private function deviceType(string $userAgent): string
    {
        if (preg_match('/iPad|Tablet/i', $userAgent) === 1) {
            return 'tablet';
        }

        if (preg_match('/Mobi|Android|iPhone/i', $userAgent) === 1) {
            return 'mobile';
        }

        return 'desktop';
    }

    private function rememberDistinctId(string $distinctId): void
    {
        $request = $this->currentRequest();

        if ($request !== null && $request->hasSession()) {
            $request->session()->put(self::SESSION_DISTINCT, $distinctId);
        }
    }

    /**
     * The bound request, or null when running outside HTTP. A queue worker /
     * artisan process still has a placeholder request bound, but that one has
     * neither a session nor a User-Agent — which is exactly the tell.
     */
    private function currentRequest(): ?Request
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = app('request');

        if (! $request instanceof Request) {
            return null;
        }

        return $request->hasSession() || $request->headers->has('User-Agent') ? $request : null;
    }

    /** An APP_KEY-derived key, so the lead-id HMAC is not keyed by APP_KEY directly. */
    private function hmacKey(): string
    {
        $appKey = (string) config('app.key');

        if (str_starts_with($appKey, 'base64:')) {
            $appKey = (string) base64_decode(substr($appKey, 7), true);
        }

        return hash_hmac('sha256', 'analytics-distinct-id', $appKey, true);
    }
}
