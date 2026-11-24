<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Client photo uploads
    |--------------------------------------------------------------------------
    |
    | Where and how a visitor's uploaded photo is stored before generation.
    |
    | - disk:      the R2 (S3-compatible) disk; the bucket is private.
    | - prefix:    a single, well-known private key prefix for ALL client photos.
    |              Storing every upload under one prefix (with a date partition,
    |              uploads/<Y-m-d>/<uuid>) keeps them findable for the Phase 7
    |              cleanup job / R2 lifecycle rule — never scatter them.
    | - ttl_hours: how long a client photo is expected to live. Kept in sync with
    |              the on-page privacy promise (deletion within 72 hours) and used
    |              to compute the expires_at hint returned to the client. The
    |              actual deletion (lifecycle / cleanup job) lands in Phase 7.
    |
    */

    'photo' => [
        'disk' => 'r2',
        'prefix' => 'uploads',
        'ttl_hours' => 72,
    ],

    /*
    |--------------------------------------------------------------------------
    | Profile pictures
    |--------------------------------------------------------------------------
    |
    | A member's avatar, set on the profile page. NOT a try-on photo: it never
    | goes to FASHN and it lives until the member removes it or the account is
    | erased, so it sits under its own prefix, outside the 72-hour sweep of
    | `uploads/`. Re-encoded to a small square JPEG on the way in.
    |
    */

    'avatar' => [
        'disk' => 'r2',
        'prefix' => 'avatars',
        'size' => 512,
        'max_kb' => 8192,
    ],

    /*
    |--------------------------------------------------------------------------
    | Generation budget protection
    |--------------------------------------------------------------------------
    |
    | Each generation is a real FASHN credit, so spend is gated BEFORE the
    | provider is ever called. Three layers, by decreasing real significance:
    |
    | - The lead gate (no lead_id → no generation) is the primary protection;
    |   it lives in the request/controller, not here.
    | - global_cap: a hard ceiling on the TOTAL number of generations started
    |   across ALL users within global_cap_period_seconds — a safety valve
    |   against a bug / attack / viral spike burning the whole balance. When
    |   reached, new generations are refused (no FASHN call).
    | - per_user_daily_limit: a per IP+contact flood guard. NOTE: this is only a
    |   flood guard, NOT real budget protection (trivially bypassed by changing
    |   IP/email). The real protection is the lead gate + global cap above.
    |
    */

    'generation' => [
        'per_user_daily_limit' => (int) env('GENERATION_PER_USER_DAILY_LIMIT', 3),
        'global_cap' => (int) env('GENERATION_GLOBAL_CAP', 200),
        'global_cap_period_seconds' => (int) env('GENERATION_GLOBAL_CAP_PERIOD', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Generation loader copy
    |--------------------------------------------------------------------------
    |
    | Status text shown while a generation runs, worded as the mobile loader
    | frame draws it (a checklist). The loader must reflect the REAL pipeline of THIS
    | generation: `background` is appended ONLY when the generation actually runs
    | the optional background-change step (with_background = true). Never promise a
    | step the pipeline will not perform — same honesty rule as the privacy copy.
    | The status endpoint composes the applicable steps per generation; the
    | front-end only renders what it is given.
    |
    | Each step is a translation key (lang/<locale>/dashboard.php
    | `loader.steps`): the words are put in at the point they are shown,
    | in the visitor's language.
    |
    */

    'loader' => [
        'steps' => [
            // The pose check and photo prep, done before the job is queued.
            'dashboard.loader.steps.analyse',
            'dashboard.loader.steps.try_on',
        ],
        // Appended only when with_background = true for the generation.
        'background_step' => 'dashboard.loader.steps.background',
        'final_step' => 'dashboard.loader.steps.final',
    ],

    /*
    |--------------------------------------------------------------------------
    | Outfit chaining (try on a SET of garments in sequence)
    |--------------------------------------------------------------------------
    |
    | The fal engine dresses ONE garment per call, so an outfit is a CHAIN: the
    | result of step N becomes the person image for step N+1 (preserve_pose +
    | aspect_ratio, added by the provider, keep pose and frame consistent across
    | the layers). All values are configurable — never hardcode "exactly 2".
    |
    | - max_items:  the most garments a single set may contain. Starts at 2;
    |               raising it is the ONLY change needed to allow bigger sets.
    | - layer_order: the fixed wearing order, base layer first. The chain dresses
    |               in THIS order regardless of the order the user picked. New
    |               layer types (e.g. 'outer') slot in here without code changes;
    |               'outer' is reserved now even though no catalog item uses it
    |               yet. A category absent from this list cannot join a set, and
    |               'dresses' is deliberately excluded (it is solo-only).
    | - full_body_layers: layers whose presence in a set requires a full-height
    |               photo (drives the SINGLE Phase-9 pass on the original photo).
    | - queue / queue_connection: a DEDICATED queue + connection for outfit jobs
    |               so a long chain never shares the single-try-on worker timeout.
    |               The connection's retry_after (config/queue.php) is kept above
    |               job_timeout so a still-running chain is never re-dispatched
    |               (which would double-spend). See config/horizon.php supervisor.
    | - job_timeout: max seconds an outfit chain may run. Sized with headroom for
    |               several layers (each ~one poll budget) — far above the ~80s a
    |               2-step chain needs today, so adding layers does not hit a wall.
    |
    */

    'outfit' => [
        'max_items' => (int) env('OUTFIT_MAX_ITEMS', 2),
        'layer_order' => ['lower_body', 'upper_body', 'outer'],
        'full_body_layers' => ['lower_body', 'outer'],
        'queue' => env('OUTFIT_QUEUE', 'outfits'),
        'queue_connection' => env('OUTFIT_QUEUE_CONNECTION', 'redis_outfits'),
        'job_timeout' => (int) env('OUTFIT_JOB_TIMEOUT', 1200),
    ],

];
