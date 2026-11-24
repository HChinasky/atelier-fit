<?php

/*
 * The "Try-On" screens (the design hand-over step1/step2, /account/try-on/step-1 and /step-2, ?lang=en).
 */

return [
    'title_upload' => 'Atelier Fit — Step 1 · Add a photo',
    'title_select' => 'Atelier Fit — Step 2 · Pick clothes',
    'by' => 'by Atelier Fit',
    'profile' => 'Profile',

    // Step 1
    'hero_alt' => 'A model in a cream suit',
    'heading' => 'AI<br>Try-On',
    'lede' => 'See how clothes look on you before you buy.',
    'tips' => [
        'full' => 'Full outfit',
        'light' => 'Good lighting',
        'figure' => 'Your figure in view',
    ],
    'step1' => 'Step 1 · Add a photo',
    'add_photo' => 'Add photo',
    // The phone's one-screen version (partials/m-tryon).
    'lede_m' => 'See how clothes look<br>on you before you buy.',
    'pick_below' => 'Pick pieces below and tap “Generate look”.',
    'change_photo' => 'Change photo',
    'step1_short' => 'Step 1',
    'step2_pill' => 'Step 2 · Pick clothes',
    'add_photo_text' => 'Upload a full-length photo of yourself to start trying on.',
    'formats' => 'JPG, PNG, WebP up to 12 MB',
    'uploading' => 'Uploading…',
    'too_big' => 'The file is too large: up to 12 MB.',
    'wrong_type' => 'Only JPG, PNG or WebP will do.',
    'upload_failed' => 'We could not upload the photo. Try another picture.',
    'consent' => 'Please accept the photo processing consent and the Terms.',

    // Step 2
    'photo_alt' => 'Your photo',
    'photo_added' => 'Photo added',
    'change' => 'Change',
    'step2' => 'Pick clothes',
    'search' => 'Brand or item',
    'search_label' => 'Search by brand or item',
    'all_brands' => 'All brands',
    'for_you' => 'For you',
    'all' => 'All',
    'chips' => [
        'dresses' => 'Dresses',
        'tops' => 'Tops',
        'bottoms' => 'Bottoms',
        'jackets' => 'Jackets',
        'shoes' => 'Shoes',
    ],
    'limit' => 'You can pick up to :count items',
    'picked_count' => 'Picked: :count',
    'empty' => 'Nothing found.',
    'like' => 'Add to favourites',
    'unlike' => 'Remove from favourites',
    'picked' => 'Picked',
    'try' => 'Try on',
    'in_tryon' => 'In try-on',
    'max_items' => 'You can pick up to :count items.',
    'generate' => 'Generate look',
    // "1 item", "2 items" — picked by Intl.PluralRules.
    'items' => [
        'one' => ':count item',
        'few' => ':count items',
        'many' => ':count items',
        'other' => ':count items',
    ],
    'busy' => 'Creating your look…',
    'retry' => 'We could not create the look. Please try again.',
    'no_connection' => 'No connection to the server. Please try again.',
    'too_long' => 'The try-on is taking longer than usual. Try again in a minute.',
    'paywall' => 'Your free try-ons are used up.',
    'paywall_link' => 'Choose a plan',
    'done' => 'Your look is ready',
    'result_alt' => 'Your look',
    'close' => 'Close',

    'nav' => [
        'label' => 'Navigation',
        'home' => 'Home',
        'tryon' => 'Try-On',
        'wardrobe' => 'Wardrobe',
        'favorites' => 'Favourites',
        'profile' => 'Profile',
    ],
];
