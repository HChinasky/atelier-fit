<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloudflare Turnstile (bot gate before sign-in codes are sent)
    |--------------------------------------------------------------------------
    |
    | Off when the secret is empty and always off in the 'local' environment
    | (see TurnstileVerifier). When on, the check fails closed: sending a code
    | costs a message, so an unverifiable request is refused.
    |
    */

    'turnstile' => [
        'site_key' => env('TURNSTILE_SITE_KEY'),
        'secret' => env('TURNSTILE_SECRET'),
        'timeout' => (int) env('TURNSTILE_HTTP_TIMEOUT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sign in with Google / Apple (Laravel Socialite)
    |--------------------------------------------------------------------------
    |
    | A provider is offered on the sign-in page only when its client_id is set
    | (see App\Services\Auth\SocialProviders); without it the button is not
    | drawn and its routes 404. Redirect URIs are built from APP_URL and must
    | be registered verbatim with the provider.
    |
    | Apple: the client secret is a short-lived JWT the provider signs on each
    | request from the .p8 key (private_key = absolute path to the file, or
    | its contents), so nothing has to be rotated by hand every six months.
    |
    */

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('APP_URL').'/auth/google/callback',
    ],

    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'client_secret' => env('APPLE_CLIENT_SECRET'),
        'key_id' => env('APPLE_KEY_ID'),
        'team_id' => env('APPLE_TEAM_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
        'redirect' => env('APP_URL').'/auth/apple/callback',
    ],

    /*
    |--------------------------------------------------------------------------
    | Virtual Try-On engine selector
    |--------------------------------------------------------------------------
    |
    | Which TryOnProvider the job resolves to: 'fal' (fal.ai, the production
    | default) or 'fashn' (the legacy FASHN provider, kept for fallback). Bound in
    | AppServiceProvider; the contract and the calling job are identical for both.
    | The test suite forces 'fashn' via phpunit.xml so the FASHN-specific provider
    | tests keep exercising that path.
    |
    */

    'tryon' => [
        // Production default is 'fashn' (the canonical engine — see CLAUDE.md hard
        // constraint #1). 'fal' is kept behind the selector as a fallback. Bound in
        // AppServiceProvider; the contract and the calling job are identical for both.
        'driver' => env('TRYON_DRIVER', 'fashn'),
    ],

    /*
    |--------------------------------------------------------------------------
    | fal.ai Try-On API (VTON engine — production default)
    |--------------------------------------------------------------------------
    |
    | Model fal-ai/image-apps-v2/virtual-try-on over fal's async queue protocol
    | (submit -> status -> result), polled by the same job loop as FASHN. The API
    | key lives ONLY in .env (never committed). No webhooks — polling only.
    |
    */

    'fal' => [
        // fal API key, sent as "Authorization: Key <FAL_KEY>". Empty in .env.example.
        'key' => env('FAL_KEY'),

        // Queue base. Submit hits {base}/{model}; status/result hit
        // {base}/{app_id}/requests/{id}[/status].
        'base_url' => env('FAL_BASE_URL', 'https://queue.fal.run'),

        // Try-on model id.
        'model' => env('FAL_TRYON_MODEL', 'fal-ai/image-apps-v2/virtual-try-on'),

        // The queue status/result endpoints address the APP id (the model's first
        // two path segments). Derived from the model when empty; override only if
        // fal changes that convention.
        'app_id' => env('FAL_TRYON_APP_ID'),

        // Keep the person's original pose between the input photo and the result.
        // image-apps-v2 defaults this to true, but we send it EXPLICITLY so the
        // behaviour does not silently change if fal flips the default: it reduces
        // pose drift (a real complaint — the result "walked away" from the source)
        // and is the main lever that brings the Before/After slider into alignment.
        // Confirmed param name + boolean type against fal's schema (June 2026).
        'preserve_pose' => filter_var(env('FAL_PRESERVE_POSE', true), FILTER_VALIDATE_BOOLEAN),

        // Output aspect ratio. image-apps-v2 outputs 4K at a fixed ratio (its own
        // default is 3:4 for fashion). Left empty = let fal use its default; set
        // one of the model's allowed enum values to force it: 1:1 | 16:9 | 9:16 |
        // 4:3 | 3:4. Only sent when non-empty, so the default path is untouched.
        'aspect_ratio' => env('FAL_ASPECT_RATIO'),

        // Per-request HTTP timeout (seconds). The poll cadence/attempt budget is
        // owned by the job and shared with FASHN via services.fashn.poll, so it is
        // not duplicated here (the job is intentionally left untouched).
        'timeout' => (int) env('FAL_HTTP_TIMEOUT', 30),

        /*
        | Retention and access of what fal keeps on its own side.
        |
        | Left alone, fal stores request/response payloads for 30 days and serves
        | generated media from a PUBLIC CDN url with no expiry — a copy of a
        | person's photo-derived image, readable by anyone holding the link, for
        | as long as fal keeps it. Neither is acceptable next to a 72-hour
        | promise, and both are controllable per request:
        |
        | - store_io=false sends "X-Fal-Store-IO: 0", so the JSON payloads (which
        |   carry the signed photo url) are not stored at all.
        | - media_ttl_seconds sets expiration_duration_seconds in
        |   "X-Fal-Object-Lifecycle-Preference", so the generated file expires on
        |   fal's side. Default 72h, matching what we promise for our own copy.
        | - private_media sets initial_acl.default = "forbid" in the same header:
        |   the CDN url returns 403 to everyone except the owning account. We are
        |   the owner, so we can still fetch it — but only by exchanging the API
        |   key for a short-lived CDN token first (see FalTryOnProvider::
        |   downloadResult). Turning this off makes results publicly readable
        |   again; it exists so the ACL can be dropped in one line if fal changes
        |   the token flow, without also losing the expiry.
        */
        'store_io' => filter_var(env('FAL_STORE_IO', false), FILTER_VALIDATE_BOOLEAN),
        'media_ttl_seconds' => (int) env('FAL_MEDIA_TTL_SECONDS', 259200),
        'private_media' => filter_var(env('FAL_PRIVATE_MEDIA', true), FILTER_VALIDATE_BOOLEAN),

        // Where the API key is exchanged for a CDN token when the media is
        // private. Separate host from the queue api, hence its own setting.
        'cdn_token_url' => env('FAL_CDN_TOKEN_URL', 'https://rest.fal.ai/storage/auth/token?storage_type=fal-cdn-v3'),
    ],

    /*
    |--------------------------------------------------------------------------
    | FASHN Try-On API (legacy engine, kept behind the selector)
    |--------------------------------------------------------------------------
    |
    | The previous VTON engine. The API key lives ONLY in .env (never committed).
    | Every generation parameter is configurable here rather than hardcoded in the
    | provider/job, so behaviour can be tuned without code changes.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Pose Detection Microservice
    |--------------------------------------------------------------------------
    |
    | The self-hosted (CPU) pose detector used to preprocess the input photo
    | (Phase 9). It is internal — reached by direct multipart upload, never via
    | a public/pre-signed URL. The token (if any) lives ONLY in .env.
    |
    */

    'pose' => [
        // Which PoseDetector binding to use: 'mediapipe' (real HTTP client) or
        // 'null' (no-op stub for tests / environments without the service).
        'driver' => env('POSE_DRIVER', 'mediapipe'),

        // Base URL of the pose service. Default targets the host from inside the
        // Laravel container (see pose-service/README.md). No trailing /pose.
        'url' => env('POSE_SERVICE_URL', 'http://host.docker.internal:8000'),

        // Optional shared secret sent as the X-API-Token header. Empty = no auth
        // (fine when the service is bound to localhost).
        'token' => env('POSE_API_TOKEN'),

        'timeout' => (int) env('POSE_HTTP_TIMEOUT', 15),

        // /color-analysis is a different shape of call from /pose: it runs two
        // MediaPipe model sets over the photo and, on the first request after a
        // restart, loads them first. Hence its own timeout, several times the
        // pose one.
        //
        // It is the INNERMOST of three, and the order is the point:
        //   HTTP (60) < job timeout (120) < queue retry_after (180)
        // so the socket gives up first and the job records a failure, rather
        // than the worker being killed mid-call or - worse - a still-running
        // job being handed to a second worker. See ColorAnalysisTimeoutsTest,
        // which pins that ordering.
        'color_timeout' => (int) env('POSE_COLOR_TIMEOUT', 60),

        // Degradation policy when the service is unavailable: 'fail_open' (default
        // — tolerate the outage, proceed without pose data) or 'fail_closed'
        // (block generation). Applied by the validator (9.2) / flow (9.5), not the
        // detector. See App\Services\Pose\PoseFailureMode for the rationale.
        'failure_mode' => env('POSE_FAILURE_MODE', 'fail_open'),

        // Fallback bbox confidence threshold reported by the Null stub (the real
        // service reports its own in the response).
        'bbox_confidence_threshold' => (float) env('POSE_BBOX_MIN_CONFIDENCE', 0.5),
    ],

    'fashn' => [
        'key' => env('FASHN_API_KEY'),
        'base_url' => env('FASHN_BASE_URL', 'https://api.fashn.ai/v1'),
        'timeout' => (int) env('FASHN_HTTP_TIMEOUT', 30),

        // Generation parameters (sent inside inputs to /v1/run).
        // Default model is tryon-max. Its input schema differs from tryon-v1.6:
        // the garment field is `product_image` (not garment_image) and the quality
        // field is `generation_mode` (not mode); it auto-detects garment category
        // and returns a single image (no num_samples). See FashnTryOnProvider.
        'model_name' => env('FASHN_MODEL_NAME', 'tryon-max'),
        // Quality lever sent as tryon-max's `generation_mode`. 'quality' is the best
        // output (skin/garment fidelity, hard cases like long dresses); same credit
        // cost. Config key kept as `mode` (env FASHN_MODE) for continuity.
        'mode' => env('FASHN_MODE', 'quality'),
        // Looks per generation. tryon-max returns ONE image per /v1/run call (it has
        // no num_samples input), so the default is 1: cost = 1 credit, one look. The
        // value still drives the budget cost in the controller and is clamped 1–4.
        'num_samples' => (int) env('FASHN_NUM_SAMPLES', 1),
        'output_format' => env('FASHN_OUTPUT_FORMAT', 'png'),

        // Content moderation level sent to FASHN.
        //
        // ⚠ DELIBERATE PRODUCT-OWNER DECISION: default is "permissive".
        // "permissive" ALLOWS trying on lingerie, swimwear and otherwise revealing
        // garments. The culturally safe default for the UAE market would be
        // "conservative" (which blocks that content) — the owner has consciously
        // chosen permissive over that safer default. Kept configurable: flip back
        // to the conservative default in ONE line (here or via FASHN_MODERATION_LEVEL).
        'moderation_level' => env('FASHN_MODERATION_LEVEL', 'permissive'),

        // Our catalog category → FASHN garment category. We know the category
        // from the feed, so an explicit map beats 'auto'. Anything unmapped
        // falls back to 'auto' in the job.
        'category_map' => [
            'upper_body' => 'tops',
            'lower_body' => 'bottoms',
            'dresses' => 'one-pieces',
        ],

        // Status polling inside the generation job.
        'poll' => [
            'interval_seconds' => (int) env('FASHN_POLL_INTERVAL', 3),
            'max_attempts' => (int) env('FASHN_POLL_MAX_ATTEMPTS', 40),
        ],

        // How long the pre-signed photo URL handed to FASHN stays valid.
        'signed_url_ttl_minutes' => (int) env('FASHN_SIGNED_URL_TTL', 30),

        // Background Change (Phase 5, OPTIONAL). A separate FASHN model run on
        // each try-on look: +1 credit PER look (N looks with background = 2N
        // credits, up to 8 for 4 looks).
        //
        // Format CONFIRMED by a live FASHN call: model_name "background-change",
        // inputs = { image, prompt }, and /status returns output as an ARRAY of
        // URLs ([".../background_change_0.png"]). The job treats the step as
        // best-effort all the same — FASHN marks this model's lifecycle as
        // experimental (it may change), so a failed background step leaves that
        // look on its original try-on background and never fails the generation.
        //
        // Disabled by default: a generation must ALSO carry with_background=true
        // (set by the controller from this flag) for the step to run, so the
        // expensive path can never switch on by accident.
        'background' => [
            'enabled' => (bool) env('FASHN_BACKGROUND_ENABLED', false),
            // Confirmed model_name; kept configurable in case FASHN versions it.
            'model_name' => env('FASHN_BACKGROUND_MODEL_NAME', 'background-change'),
            // Scene descriptions. If more than one is given, looks are varied
            // round-robin across them (secondary; one prompt is fine). Pipe-
            // separated in env because a prompt itself contains commas.
            'prompts' => array_values(array_filter(array_map(
                'trim',
                explode('|', (string) env('FASHN_BACKGROUND_PROMPTS', 'Dubai Marina, street style')),
            ))),
        ],
    ],

];
