<?php

/*
 * Validation messages of the Form Requests (app/Http/Requests), keyed by the
 * request and then by "field.rule" as in their messages().
 */

return [
    'sign_in' => [
        'code' => 'Enter the six-digit code from the e-mail.',
    ],

    'avatar' => [
        'required' => 'Please choose a photo.',
        'type' => 'Please use a JPG, PNG or WebP image.',
        'max' => 'This photo is too large: up to :mb MB.',
        'unreadable' => 'This image could not be read. Please try another one.',
        'updated' => 'Profile photo updated.',
        'removed' => 'Profile photo removed.',
    ],

    'generation' => [
        'lead_required' => 'Leave a contact first to start the try-on.',
        'lead_invalid' => 'The contact is not valid.',
        'lead_missing' => 'The contact was not found. Please leave it again.',
        'product_required' => 'Please pick a piece to try on.',
        'product_invalid' => 'The piece is not valid.',
        'product_missing' => 'The piece you picked was not found. Please pick another one.',
        'photo_required' => 'Please upload a photo first.',
        'photo_invalid' => 'The photo is not valid. Please upload it again.',
        'consent_required' => 'Your consent to processing the photo is needed first.',
        'consent_invalid' => 'The consent is not valid. Please upload the photo again.',
        'consent_missing' => 'Your consent to processing the photo is needed. Please upload the photo again.',
        // The outfit (several pieces at once).
        'products_required' => 'Please pick the pieces for the look.',
        'products_min' => 'Please pick at least two pieces for the look.',
        'products_max' => 'You can try on no more than :max pieces at a time.',
        'products_distinct' => 'Please pick different pieces.',
        'products_missing' => 'One of the pieces you picked was not found. Please pick another one.',
    ],

    'photo' => [
        'required' => 'Please choose a photo first.',
        'type' => 'Please use a JPG, PNG or WebP photo.',
        'max' => 'The photo is too large: up to :mb MB.',
        'failed' => 'The photo did not upload. Please try again.',
        'consent' => 'Consent is required before a photo can be processed.',
        'consent_changed' => 'The consent wording has changed. Please review and agree again.',
        'terms' => 'The Terms of Use must be accepted before a photo can be processed.',
        'terms_changed' => 'The Terms of Use have changed. Please review and accept them again.',
    ],

    'color' => [
        'photo_required' => 'Please add a photo first.',
        'photo_type' => 'A JPEG, PNG, WebP or HEIC photo will do.',
        'photo_max' => 'The file is too large. Send the photo as it is, without upscaling.',
        'consent' => 'Your consent to processing the photo is needed to find your colour type.',
    ],
];
