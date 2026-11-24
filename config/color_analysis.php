<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Colour analysis (internal)
    |--------------------------------------------------------------------------
    |
    | An admin-only instrument for checking the colour engine: upload a photo,
    | see what it measured. Gated by the `color_analysis` feature flag AND the
    | admin role — the flag decides whether the routes exist, the role decides
    | who gets past them.
    |
    | Storage deliberately reuses the try-on photo disk and prefix. That is not
    | tidiness: app:purge-expired-photos sweeps ONE prefix by object age, so
    | living under it is what buys these photos the same 60-hour deletion the
    | privacy policy promises, with nothing new to maintain.
    |
    */

    'storage' => [
        // Same disk and prefix as fitting_room.photo, with a sub-folder so the
        // two uses stay tellable apart when someone is looking at the bucket.
        'folder' => env('COLOR_ANALYSIS_FOLDER', 'color'),
    ],

    /*
    | The queue. Its own connection and supervisor (config/horizon.php) so an
    | admin experimenting with the engine can never tie up the try-on workers:
    | this is an internal tool and must always yield to paying work.
    */

    'queue' => env('COLOR_QUEUE', 'color'),
    'queue_connection' => env('COLOR_QUEUE_CONNECTION', 'redis_color'),

    /*
    | Upload limits for the admin form. Deliberately generous — the engine's own
    | absolute resolution floors are what decide whether a photo is measurable,
    | and a small file that clears them is fine.
    */

    'upload' => [
        'max_kilobytes' => (int) env('COLOR_ANALYSIS_MAX_KB', 15 * 1024),
        'mimes' => ['jpeg', 'jpg', 'png', 'webp', 'heic', 'heif'],
    ],

    /*
    | How many of an admin's own recent profiles the page lists.
    */

    'recent_limit' => (int) env('COLOR_ANALYSIS_RECENT', 20),

    /*
    |--------------------------------------------------------------------------
    | Confidence, in words
    |--------------------------------------------------------------------------
    |
    | The member sees how sure the reading is as low / medium / high, never
    | the engine's number. Below `medium` — the engine's own
    | min_confidence_for_season (classifier.json) — is low, and so is anything
    | the engine itself flagged low_confidence.
    |
    */

    'confidence_levels' => [
        'medium' => (float) env('COLOR_CONFIDENCE_MEDIUM', 0.4),
        'high' => (float) env('COLOR_CONFIDENCE_HIGH', 0.65),
    ],

    /*
    |--------------------------------------------------------------------------
    | Garment colour against a season's palette
    |--------------------------------------------------------------------------
    |
    | How a product's measured colour is judged to suit a season
    | (GarmentPaletteMatcher). Distances are CIEDE2000. PRELIMINARY: set on
    | the dev catalog against the draft palettes, not on anybody's verdict of
    | what suits whom. Change them, then run catalog:measure-colors --rematch.
    |
    | - max_delta_e: a colour this close to one of the season's colours to wear
    |   counts as that colour. The palettes hold 18 wearable swatches each, so
    |   it has to be generous enough to cover the space between them.
    | - clash_share: a colour covering at least this much of the garment that
    |   sits nearer the season's colours to avoid spoils the piece, even when
    |   its main colour fits.
    | - min_share: colours smaller than this (a print dot, a trim) are not
    |   judged at all.
    |
    */

    'garment_match' => [
        'max_delta_e' => (float) env('COLOR_GARMENT_MAX_DELTA_E', 15.0),
        'clash_share' => (float) env('COLOR_GARMENT_CLASH_SHARE', 0.25),
        'min_share' => (float) env('COLOR_GARMENT_MIN_SHARE', 0.15),
    ],

    /*
    | How long to wait for a product photo from the shop, and for the service to
    | measure it. A product photo is small; a minute is the whole budget.
    */

    'garment_fetch_timeout' => (int) env('COLOR_GARMENT_FETCH_TIMEOUT', 20),

];
