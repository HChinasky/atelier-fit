<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Server-side product analytics (PostHog EU)
    |--------------------------------------------------------------------------
    |
    | Every event is sent FROM OUR SERVER over a queued job — no analytics
    | JavaScript, no analytics cookies, no pixels ever load on a page (that is a
    | published promise in the Cookie Policy and Privacy Policy §5). Identities
    | are pseudonymous: an anonymous UUID in the existing Laravel session before
    | a contact is captured, then an HMAC of the lead id — never the phone,
    | email, name, IP or any photo/result URL.
    |
    | OFF by default. Turning it on in production is a deliberate decision that
    | must FOLLOW the legal-document update (Privacy Policy §3.6/§5, Cookie
    | Policy §5) — the documents describe the processing before it starts.
    |
    */

    'enabled' => (bool) env('ANALYTICS_ENABLED', false),

    'posthog' => [
        // EU cloud only — event data stays in PostHog's Frankfurt region.
        'host' => rtrim((string) env('POSTHOG_HOST', 'https://eu.i.posthog.com'), '/'),

        // The project API key (phc_…) used for /capture. Write-only by design.
        'key' => env('POSTHOG_KEY'),

        // Personal API key + numeric project id — needed ONLY for the erasure
        // step (deleting a person via the private API). Leave empty to skip
        // that step; event capture does not use them.
        'personal_key' => env('POSTHOG_PERSONAL_KEY'),
        'project_id' => env('POSTHOG_PROJECT_ID'),

        'timeout' => (int) env('POSTHOG_HTTP_TIMEOUT', 5),
    ],

    // Dedicated queue so an analytics backlog can never delay a generation.
    'queue' => env('ANALYTICS_QUEUE', 'analytics'),

    /*
    | Team traffic marking. An event is stamped is_internal=true when the
    | request IP is in this comma-separated list OR the request carries the
    | opt-in cookie below (set manually in the team's browsers via dev tools —
    | the application never sets it for visitors).
    */
    'internal_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ANALYTICS_INTERNAL_IPS', '')),
    ))),
    'internal_cookie' => env('ANALYTICS_INTERNAL_COOKIE', 'oys_internal'),

    /*
    | Engine cost accounting for generation_completed.cost_usd. One engine
    | credit ≈ this many USD; null/0 = cost_usd is omitted from events.
    */
    'cost_per_credit_usd' => env('ANALYTICS_COST_PER_CREDIT_USD') !== null
        ? (float) env('ANALYTICS_COST_PER_CREDIT_USD')
        : null,

    // Deployed revision stamped on every event (set by the deploy script to
    // the git sha). Empty = property omitted.
    'app_version' => env('APP_VERSION'),

];
