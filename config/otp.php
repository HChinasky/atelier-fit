<?php

return [

    /*
    |--------------------------------------------------------------------------
    | One-time sign-in codes (e-mail)
    |--------------------------------------------------------------------------
    |
    | The OTP core behind passwordless e-mail login. The code itself is stored
    | ONLY as a hash (bcrypt via Hash::make) and never appears in the database
    | or the logs. Delivery is the application mailer (MAIL_MAILER): 'log'
    | locally so the code can be read from laravel.log, the Resend SMTP relay
    | in production — see .env.example.
    |
    */

    // Digits in a code and how long it stays valid. 10 minutes per spec.
    'code_length' => 6,
    'ttl_minutes' => (int) env('OTP_TTL_MINUTES', 10),

    // Wrong entries allowed per code before the address is blocked (the UI
    // shows "1 attempt left" after the first miss), and how long that lasts.
    'max_failed_attempts' => 2,
    'block_minutes' => (int) env('OTP_BLOCK_MINUTES', 180),

    /*
    |--------------------------------------------------------------------------
    | Send throttling
    |--------------------------------------------------------------------------
    |
    | Layered like the generation gate: per-requester limits blunt abuse of a
    | single client, the per-address limit stops flooding a victim's inbox,
    | and the global fuse is the app-wide safety valve — when it trips, code
    | sending is temporarily off for everyone (logged, polite error).
    |
    | - per_requester_minute / per_requester_hour: keyed by fingerprint + IP.
    | - per_address / per_address_window_minutes: max sends to one address
    |   within the window (2 = the initial send plus one resend; the window
    |   matches the 3-hour block so the UI story stays consistent).
    | - global_per_minute: total sends across the app per minute.
    |
    */

    'limits' => [
        'per_requester_minute' => 1,
        'per_requester_hour' => 3,
        'per_address' => 2,
        'per_address_window_minutes' => (int) env('OTP_BLOCK_MINUTES', 180),
        'global_per_minute' => (int) env('OTP_GLOBAL_SENDS_PER_MINUTE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Test addresses — a deliberate bypass for demo and QA
    |--------------------------------------------------------------------------
    |
    | For the addresses listed here NO message is sent and test_code is
    | accepted as the code. It exists so demo and QA access does not depend on
    | a working mailer, and so the owner can get in behind the coming-soon
    | curtain (FEATURE_COMING_SOON) while everyone else is waitlisted.
    |
    | It FAILS CLOSED, on purpose. The list alone opens nothing: without a
    | test_code of exactly code_length digits the bypass stays off, so a stray
    | OTP_TEST_CODE=true leaves it disabled rather than half-enabled.
    |
    | Know what the trade is: nobody has to OWN a listed address to use it —
    | there is no message to receive — so for these addresses the code is the
    | only thing between a visitor and the account. Keep it random and secret.
    | What bounds guessing is the per-requester send throttle above (3 sends an
    | hour, two attempts per code), which the bypass deliberately still obeys;
    | only the per-address cap is lifted, because that cap is what would lock
    | a tester out after two logins.
    |
    */

    'test_addresses' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OTP_TEST_ADDRESSES', '')),
    ))),

    'test_code' => trim((string) env('OTP_TEST_CODE', '')),

];
