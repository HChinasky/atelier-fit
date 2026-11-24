<?php

/*
|--------------------------------------------------------------------------
| Capsules (CapsuleBuilder)
|--------------------------------------------------------------------------
|
| A capsule is a whole look from the catalog in the member's palette, for
| one occasion: top + bottom + shoes, or a dress + shoes, with a hat when one
| fits. Pieces are picked from what GarmentPaletteMatcher says suits the
| member's season; the rules below keep the look a look, not a pile.
|
*/

return [

    // The occasions a member can pick, in page order.
    'occasions' => ['office', 'evening', 'casual'],

    // Occasions whose every look holds at least one garment or pair of shoes
    // tagged for it. Casual is left out on purpose: a look of base pieces IS
    // a casual look; for the office or an evening it would be a guess.
    'require_occasion_piece' => ['office', 'evening'],

    // Looks per occasion: fewer than min only when the catalog cannot make more.
    'variants' => ['min' => 3, 'max' => 5],

    // How many of the best pieces per slot are combined (bounds the search).
    'candidates_per_slot' => 8,

    // At most this many pieces in the palette's accent colours per look; the
    // rest are neutrals/base, and at least one piece always is.
    'max_accents' => 2,

    // Shoes and headwear must be a neutral, or "in tone": within this many
    // CIEDE2000 units of a garment's main colour (≈10 is another shade of
    // the same colour family).
    'tone_delta_e' => 20,

    // Ranking only, never shown: a piece tagged for THIS occasion beats a
    // universal base piece of the same colour match.
    'occasion_bonus' => 0.1,

];
