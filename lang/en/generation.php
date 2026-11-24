<?php

/*
 * What the try-on endpoints answer (GenerationGate, the generation
 * controllers, the status resource).
 */

return [
    'signup_required' => 'The free try-ons are used up. Sign in and get :count more.',
    'daily_limit' => 'You have reached the daily try-on limit. Please try again tomorrow.',
    'credits_exhausted' => 'Your credits have run out. Upgrade your plan to keep trying on.',
    'unavailable' => 'The try-on service is temporarily unavailable. Please try again later.',
    'photo_service_down' => 'The photo service is temporarily unavailable. Please try again later.',
    'lead_missing' => 'The contact was not found. Please leave it again.',
    'failed' => 'Sorry, the look could not be created. Try another photo or another piece.',

    // Field names in validation messages.
    'attributes' => [
        'lead_id' => 'contact',
        'product_id' => 'piece',
        'photo_key' => 'photo',
    ],

];
