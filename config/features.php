<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature flags
    |--------------------------------------------------------------------------
    |
    | Coarse on/off switches for whole feature areas. Every flag defaults to
    | OFF so a plain deploy never exposes unfinished work; enabling is an
    | explicit .env decision per environment.
    |
    | - membership: the Membership & Account area (Stage A: UI only — /pricing
    |   and /account/* pages with mock data, no auth/billing/DB). While off,
    |   all membership routes 404.
    |
    | - color_analysis: the colour engine's surfaces — the internal instrument
    |   at /admin/color (admin-only on top of the flag), the stylist labelling
    |   queue at /stylist/color, and the member's own page at /account/color,
    |   where a face photo is analysed only with an explicit consent tick and
    |   the member sees a season and a palette, never the measurements. While
    |   off, every one of those routes is a 404 and the account nav item is
    |   not drawn.
    |
    | - fitting_room: the try-on surface — the /ai-fitting-room page AND the
    |   endpoints that feed it (catalog, lead, photo upload, generate). It is
    |   an internal testing surface, not a client-facing page, so it ships
    |   closed: while off, every one of those routes is a plain 404 and no
    |   FASHN call can be started at all. Turn it on only while testing.
    |
    */

    'membership' => (bool) env('FEATURE_MEMBERSHIP', false),

    // Pre-launch. With membership on, the sign-in field keeps the e-mail as a
    // waiting-list lead (tag "waitlist") instead of sending a code, and the
    // visitor lands on /coming-soon. Addresses in OTP_TEST_ADDRESSES still get
    // in, so the owner and QA can reach the account behind the curtain.
    // Requires FEATURE_MEMBERSHIP: without it there is no sign-in field.
    'coming_soon' => (bool) env('FEATURE_COMING_SOON', false),

    'fitting_room' => (bool) env('FEATURE_FITTING_ROOM', false),

    // Named COLOR_ANALYSIS_ENABLED rather than FEATURE_* because that is what
    // the brief specified; the flag itself works like every other one here.
    'color_analysis' => (bool) env('COLOR_ANALYSIS_ENABLED', false),

];
