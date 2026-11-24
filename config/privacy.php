<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Data retention
    |--------------------------------------------------------------------------
    |
    | How long each category of personal data lives before app:cleanup-expired-data
    | removes it. These periods are PUBLISHED PROMISES — they are mirrored in
    | the privacy policy, so changing a value here changes
    | what we tell people. Keep the two in step.
    |
    | Every value is env-driven on purpose: the periods are lawyer-proposed
    | defaults and are expected to be adjusted after legal review, without a code
    | change or a deploy of new logic.
    |
    | A value of 0 (or less) DISABLES that rule — nothing is deleted for it. That
    | is the safe direction for a misconfigured env: a typo keeps data rather than
    | destroying it. The command reports every disabled rule so it cannot go
    | unnoticed.
    |
    | - lead_months:                a captured contact, counted from the last time
    |                               the record was touched (its last contact).
    | - account_inactivity_months:  an account with no sign-in activity for this
    |                               long is removed, with its leads, generations
    |                               and credit ledger.
    | - log_days:                   application log files older than this are
    |                               deleted from storage/logs.
    | - consent_years:              the record proving a photo consent was given.
    |                               Deliberately the LONGEST period here: it has to
    |                               outlive the photo (72h) and the contact record,
    |                               or we lose the proof before any claim could be
    |                               raised.
    |
    | NOT covered here, deliberately: welcome_grants. That table holds an
    | irreversible hash of an e-mail address and is kept FOREVER, surviving account
    | deletion, so a welcome bonus cannot be claimed twice. The privacy policy
    | discloses this explicitly. Never add it to a cleanup rule.
    |
    */

    'retention' => [
        'lead_months' => (int) env('LEAD_RETENTION_MONTHS', 12),
        'account_inactivity_months' => (int) env('ACCOUNT_INACTIVITY_MONTHS', 24),
        'log_days' => (int) env('LOG_RETENTION_DAYS', 90),
        'consent_years' => (int) env('CONSENT_RECORD_YEARS', 3),

        /*
        | Colour profiles — what the colour engine measured from a face photo.
        |
        | The photo itself is gone in hours; these are the NUMBERS left behind:
        | skin, hair, iris and sclera in CIELAB. They are not a picture, but they
        | are measurements of a person's body, derived from their face, and they
        | are kept against a user id — so they need a period of their own rather
        | than living forever because nobody thought about them.
        |
        | Open question: 90 days is an engineering placeholder, not advice. Two
        | questions decide the real number, and neither is ours to answer:
        |   - do colour measurements derived from a face count as biometric data
        |     under UAE PDPL? If they do, the basis, the period and the consent
        |     wording all change;
        |   - is the analysis a service the person expects to be able to return
        |     to (which argues for longer), or a one-off diagnostic (shorter)?
        | Today the feature is admin-only and internal, so the shortest period
        | that is still useful for checking the engine is the safe direction.
        |
        | Deleting a profile takes its image rows with it (cascade), so this can
        | never leave a row pointing at an object that has already been swept.
        */
        'color_profile_days' => (int) env('COLOR_PROFILE_RETENTION_DAYS', 90),

        /*
        | Marketing permission. An ACTIVE permission is kept while it is in force
        | — it is the proof we were allowed to write to that contact. Once it is
        | withdrawn the record becomes proof of the opt-OUT, which is worth
        | keeping for a while and not forever; this is how long after withdrawal
        | it survives.
        */
        'marketing_consent_years' => (int) env('MARKETING_CONSENT_RECORD_YEARS', 3),

        /*
        | The welcome-grant identity hash. It has to outlive the account, or erasing
        | and re-registering would collect the bonus again — but it does not have
        | to outlive it forever. Counted from the moment the account was erased
        | (welcome_grants.account_removed_at); a hash whose account still exists
        | is never swept.
        */
        'welcome_grant_months' => (int) env('WELCOME_GRANT_RETENTION_MONTHS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Photo hard delete
    |--------------------------------------------------------------------------
    |
    | How old a stored image may get before the application deletes it ITSELF,
    | through the storage API, rather than waiting for the bucket lifecycle rule.
    |
    | Why both: an object lifecycle rule is not a deadline. Cloudflare expires
    | objects "within 24 hours of expiry, sometimes longer", so a 72-hour rule can
    | genuinely delete at 80-something hours — while the privacy policy promises
    | 72. The rule stays as a backstop for anything the sweep misses; the promise
    | is kept by the sweep.
    |
    | Hence the DEFAULT OF 60 HOURS, not 72: the gap is deliberate headroom for a
    | missed run, a slow sweep or a storage error, so that physical deletion still
    | lands inside the 72 hours we published. Raising this above 72 breaks the
    | promise outright; raising it near 72 leaves no room for anything to go wrong.
    |
    | 0 or less disables the sweep (same safe direction as the periods above) — the
    | lifecycle rule then becomes the only mechanism, and the command says so.
    |
    */

    'photo_hard_delete_hours' => (int) env('PHOTO_HARD_DELETE_HOURS', 60),

];
