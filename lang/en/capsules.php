<?php

/*
 * Capsules: ready looks from the catalog in the member's palette, by occasion.
 * The explanations say only what is true of the look: the palette, the
 * occasion and how the colours are laid out in it.
 */

return [

    'occasions' => [
        'office' => 'Office',
        'evening' => 'Evening',
        'casual' => 'Casual',
    ],

    // Under the capsule's name, on its cover and its page.
    'blurbs' => [
        'office' => 'Understated pieces for the working day in your palette.',
        'evening' => 'Pieces for going out in your colours, from the key piece to the shoes.',
        'casual' => 'Everyday basics that go with each other and with you.',
    ],

    'pieces' => ':count piece|:count pieces',

    'occasion_for' => [
        'office' => 'for the office',
        'evening' => 'for the evening',
        'casual' => 'for every day',
    ],

    'slots' => [
        'upper_body' => 'Top',
        'lower_body' => 'Bottom',
        'dresses' => 'Dress / set',
        'shoes' => 'Shoes',
        'headwear' => 'Headwear',
    ],

    // Between slot names inside the quotes of 'missing'.
    'slots_glue' => '”, “',

    'slots_lower' => [
        'upper_body' => 'top',
        'lower_body' => 'bottom',
        'dresses' => 'dress',
        'shoes' => 'shoes',
        'headwear' => 'headwear',
    ],

    // Joins the two accent colours in 'explain.two_accents'.
    'accents_glue' => ' and ',

    'explain' => [
        'neutral' => 'All in the neutral and base colours of the “:season” palette — a calm look :occasion.',
        'one_accent' => 'A neutral base from the “:season” palette and one accent — :accents — :occasion.',
        'two_accents' => 'A neutral base from the “:season” palette and two accents — :accents — :occasion.',
    ],

    'missing' => 'We cannot put a look together :occasion yet: the catalog has nothing in your colours under “:slots”. As soon as it does, the capsules will gather here.',
    'no_occasion_pieces' => 'The catalog has no pieces in your colours made :occasion yet — and we do not build such a look from basics alone. We are adding new pieces to the catalog.',
    'no_balanced_look' => 'There are pieces in your colours :occasion, but together they do not make a balanced look — too many bright accents. We are adding new pieces to the catalog.',

    // The pages: the list (membership/capsules) and one capsule (membership/capsule).
    'ui' => [
        'title' => 'Atelier Fit — Capsules',
        'title_one' => 'Atelier Fit — “:name” capsule',
        'eyebrow' => 'Capsules & trend-books',
        'heading' => 'Ready capsules',
        'lede' => 'Complete looks for every occasion in your palette. Open a capsule, try the pieces on with AI and buy the ones you like.',
        'locked_title' => 'Stylist capsules',
        'locked_text' => 'Curated trend-books unlock on Couture and above.',
        'locked_cta' => 'Upgrade to Couture',
        'colour_off' => 'Capsules are built from your colour-type palette. Colour analysis is not available right now — capsules will appear as soon as it is.',
        'no_season' => 'Capsules are built from your colour-type palette, and yours has not been found yet. :link — and looks in your colours will appear here.',
        'no_season_link' => 'Take the colour analysis',
        'gathering' => 'Looks are still being put together',
        'colours' => 'Capsule colours',
        'open' => 'View capsule',
        'back' => 'Back to capsules',
        'pieces_heading' => ':count in the capsule',
        'favorite' => 'Add to favourites',
        'try_on' => 'Try on',
        'try_on_unavailable' => 'This piece cannot be tried on yet',
        'buy' => 'Buy',
        'ways' => 'How to wear it',
        'ways_sub' => 'Looks from this capsule’s pieces, put together for your palette.',
        'match' => 'Palette match — :percent%.',
        'look' => 'Look :n',
    ],

];
