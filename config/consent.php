<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Photo-processing consent
    |--------------------------------------------------------------------------
    |
    | The explicit consent a visitor gives BEFORE their photo is uploaded. Nothing
    | is processed without it: the upload endpoint refuses a request that does not
    | carry it, and every generation endpoint refuses a request whose consent is
    | missing or given against an older wording.
    |
    | ⚠️ DRAFT WORDING. The text below is a sample, and it still needs review by a
    | qualified data-protection lawyer for the jurisdiction you operate in. It is deliberately short enough to be read. Replace it with the
    | reviewed wording before launch — and when you do, BUMP THE VERSION.
    |
    | version:  the wording in force. Consent is only valid for the text the person
    |           actually saw, so it is recorded per version (photo_consents) and a
    |           record made against an older version no longer authorises new
    |           processing. Bump this whenever the wording, the AI provider or the
    |           retention period changes materially — that is what asks everyone to
    |           agree again. Keep it short and stable (a plain counter is fine).
    |
    | language: the consent must be in the language of the interface the end user
    |           actually sees. The public-facing surfaces (the sign-in page and the
    |           account area) are English for the UAE market, so the wording is
    |           English. The internal try-on page still carries Ukrainian copy from
    |           earlier phases — that is an internal testing surface, and it is NOT
    |           the language this consent follows. Never hardcode the consent text
    |           into a template: it belongs here, versioned, in one place.
    |
    */

    'version' => (string) env('CONSENT_VERSION', '2'),

    /*
    | Whether the photo consent and the Terms boxes are asked for at all.
    |
    | Off (the default) on the owner's call of 2026-10-13: the boxes are hidden
    | on every surface (try-on, colour analysis, the onboarding selfie, the
    | internal fitting room) and the endpoints stop requiring them. Off means
    | NOTHING is recorded — no photo_consents row, no consented_at stamp — so
    | the records never claim an agreement nobody gave. The 72-hour retention
    | line stays on the page either way: it is information, not a consent.
    | On brings every box and every server-side gate back as they were.
    */
    'required' => (bool) env('CONSENT_REQUIRED', false),

    // The checkbox label itself — the short version.
    //
    // It asks for consent to process the visitor's OWN photo, and nobody else's.
    // The reason: a person uploading someone else's picture
    // cannot give explicit consent on that person's behalf, so a permission to
    // upload "another adult who agreed" was authorising something we cannot
    // actually verify or rely on. Until a flow exists that collects consent from
    // the person IN the photo, the answer is: your own photo only.
    //
    // Note what it does NOT say: a flat "stored for up to 72 hours". That is true
    // of OUR copy; the AI provider keeps its own under its own retention, and
    // pretending otherwise would promise something we do not control.
    'text' => 'I agree that my photo will be processed by AI, including being sent to a third-party AI provider outside the UAE. The photo is of me — not of anyone else — I am at least 18 years old, and it is mine to upload. Our copy is deleted within 72 hours; the AI provider keeps its own copy under its own retention.',

    /*
    | Whether a visitor may upload a photo of someone OTHER than themselves.
    |
    | Off, and the interface and the wording are built around that. The seam is
    | kept deliberately: a third-party-subject flow is possible later, but it needs
    | consent from the person depicted — not a checkbox ticked on their behalf —
    | so turning this on is a product decision with a legal precondition, not a
    | config change. Nothing reads it as permission today.
    */
    'allow_third_party_subject' => false,

    /*
    |--------------------------------------------------------------------------
    | Marketing consent (separate, optional, opt-in)
    |--------------------------------------------------------------------------
    |
    | A contact detail is required to reach the service. Sending marketing to that
    | contact is a DIFFERENT purpose and needs its own freely-given consent — under
    | UAE rules, obtained beforehand, recorded, and withdrawable at no cost. So it
    | is a separate unticked box, never a condition of using the service, and it is
    | recorded separately (marketing_consents) with its own version.
    |
    | ⚠️ DRAFT WORDING — same standing as the photo consent above.
    */

    'marketing' => [
        'version' => (string) env('MARKETING_CONSENT_VERSION', '1'),
        'text' => 'You may also send me styling offers and promotions on this contact. Optional — I can unsubscribe at any time, free of charge.',
    ],

    // Where the full explanations live. Empty until the reviewed documents are
    // actually published; the template only renders a link when there is one.
    'links' => [
        'privacy_policy' => (string) env('CONSENT_PRIVACY_URL', ''),
        'ai_disclosure' => (string) env('CONSENT_AI_DISCLOSURE_URL', ''),
    ],

];
