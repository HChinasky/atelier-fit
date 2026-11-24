<?php

/*
|--------------------------------------------------------------------------
| Membership funnel & credits (Stage B3)
|--------------------------------------------------------------------------
|
| The "2+4" funnel: a guest gets `guest_free_generations` try-ons per contact
| (lifetime, counted in the DB — failed ones do not count), then must sign in
| (e-mail code, Google or Apple), which grants `welcome_credits` — once per
| e-mail address EVER (see welcome_grants). All of it only acts while FEATURE_MEMBERSHIP is
| on; with the flag off the guest flow runs exactly as before.
|
*/

return [

    'welcome_credits' => (int) env('MEMBERSHIP_WELCOME_CREDITS', 4),

    'guest_free_generations' => (int) env('MEMBERSHIP_GUEST_FREE_GENERATIONS', 2),

    // Whether the plan prices include VAT. Decided 2026-11-19: they do, which
    // /pricing and section 13.1 of the Terms both say. Setting the env var to
    // false flips both statements' basis for the page; null (empty env) makes
    // the page silent about VAT. Keep it in step with the Terms.
    'prices_include_vat' => env('MEMBERSHIP_PRICES_INCLUDE_VAT', true) === ''
        ? null
        : filter_var(env('MEMBERSHIP_PRICES_INCLUDE_VAT', true), FILTER_VALIDATE_BOOLEAN),

    // Where "book a consultation with our stylist" leads (a booking page or a
    // WhatsApp link). Empty → the dashboard renders those buttons inert
    // rather than pointing them somewhere made up.
    'stylist_booking_url' => env('STYLIST_BOOKING_URL') ?: null,

    // The main site, where "Back to Atelier Fit" on the coming-soon page
    // leads. This app lives on a subdomain of it.
    'home_url' => rtrim((string) env('SITE_HOME_URL', 'https://atelierfit.example'), '/'),

];
