<?php

/*
 * The 12 seasons, said in words.
 *
 * Keys are the engine's own Season slugs (see
 * services/color-engine/src/color_engine/models.py) and the group names the
 * palette generator writes — both are a contract, so never rename one here
 * without changing it there.
 *
 * Copy lives here rather than in the seasons table because it is copy: it is
 * translated, it gets reworded, and a column would mean a migration every time
 * somebody improved a sentence and one language winning by accident.
 *
 * Each description says what the colouring IS, in terms somebody can check
 * against their own face — not what it "means" and not what to buy. The season
 * is a reading of measurements, and the words should sound like one.
 */

return [

    'draft_banner' => 'Draft. The season and the palettes have not been checked by a stylist.',
    'draft_banner_uncalibrated' => 'Draft. The season and the palettes have not been checked by a stylist. White balance is not calibrated.',
    'draft_badge' => 'Draft',
    'low_confidence_badge' => 'Low confidence',

    'names' => [
        'light_spring' => 'Light spring',
        'true_spring' => 'True spring',
        'bright_spring' => 'Bright spring',
        'light_summer' => 'Light summer',
        'true_summer' => 'True summer',
        'soft_summer' => 'Soft summer',
        'soft_autumn' => 'Soft autumn',
        'true_autumn' => 'True autumn',
        'deep_autumn' => 'Deep autumn',
        'deep_winter' => 'Deep winter',
        'true_winter' => 'True winter',
        'bright_winter' => 'Bright winter',
    ],

    'descriptions' => [
        'light_spring' => 'Light first, warm second. Fair colouring with little distance between hair, skin and eyes, and a golden rather than a rosy cast.',
        'true_spring' => 'Warm first, clear second. Golden colouring with real saturation in the eyes and the skin.',
        'bright_spring' => 'Clear first, warm second. Strong contrast between hair, skin and eyes, with a warm cast over all of it.',
        'light_summer' => 'Light first, cool second. Fair colouring with little distance between hair, skin and eyes, and a rosy rather than a golden cast.',
        'true_summer' => 'Cool first, muted second. Rosy colouring with the hair, skin and eyes sitting close together in tone.',
        'soft_summer' => 'Muted first, cool second. Little saturation anywhere, and a cool cast over it.',
        'soft_autumn' => 'Muted first, warm second. Little saturation anywhere, and a golden cast over it.',
        'true_autumn' => 'Warm first, muted second. Golden colouring that reads rich rather than bright.',
        'deep_autumn' => 'Deep first, warm second. Dark hair and eyes with a golden cast rather than a blue one.',
        'deep_winter' => 'Deep first, cool second. Dark hair and eyes with a cool cast.',
        'true_winter' => 'Cool first, clear second. A cool cast with real saturation and real contrast.',
        'bright_winter' => 'Clear first, cool second. Strong contrast between hair, skin and eyes, with a cool cast over all of it.',
    ],

    /*
     * The season's character in three words — the chips under its name on the
     * account page. Two words are the season's axes (temperature first), the
     * third its character; "Bright" rather than "Clear", as people hear it.
     * None of the engine's numbers reach here.
     */
    'traits' => [
        'light_spring' => ['Light', 'Warm', 'Delicate'],
        'true_spring' => ['Warm', 'Bright', 'Golden'],
        'bright_spring' => ['Warm', 'Bright', 'Contrasting'],
        'light_summer' => ['Light', 'Cool', 'Delicate'],
        'true_summer' => ['Cool', 'Muted', 'Rosy'],
        'soft_summer' => ['Soft', 'Cool', 'Muted'],
        'soft_autumn' => ['Soft', 'Warm', 'Muted'],
        'true_autumn' => ['Warm', 'Muted', 'Rich'],
        'deep_autumn' => ['Deep', 'Warm', 'Rich'],
        'deep_winter' => ['Deep', 'Cool', 'Contrasting'],
        'true_winter' => ['Cool', 'Bright', 'Contrasting'],
        'bright_winter' => ['Cool', 'Bright', 'Contrasting'],
    ],

    /*
     * What to do with it in a wardrobe: four tips per season. About colour,
     * not cuts or brands — the page knows only the season.
     */
    'tips' => [
        'light_spring' => [
            'Near the face: light warm shades — peach, cream, light turquoise.',
            'Instead of black: light beige, camel or warm grey.',
            'Keep contrast low: light colours paired with each other, no sharp dark patches.',
            'Jewellery: light gold.',
        ],
        'true_spring' => [
            'Choose warm, saturated colours: coral, golden yellow, grass green.',
            'Instead of black: dark chocolate or a warm navy.',
            'White should be creamy, not a cold optical white.',
            'Jewellery: yellow gold, brass.',
        ],
        'bright_spring' => [
            'Wear bright, clear colours near the face — they lift it rather than overpower it.',
            'Contrast works for you: pair light with saturated.',
            'Dusty, greyish shades next to the face make it look dull.',
            'Jewellery: polished gold.',
        ],
        'light_summer' => [
            'Near the face: light cool pastels — powder pink, lavender, sky blue.',
            'Instead of black: blue-grey or a mid-depth graphite.',
            'Pair light tones with each other, without sharp contrast.',
            'Jewellery: silver, white gold.',
        ],
        'true_summer' => [
            'Build the wardrobe on cool, muted shades: blue-grey, raspberry pink, mint.',
            'Instead of black: a dark blue-grey.',
            'Tone-on-tone reads better than sharp light-and-dark pairs.',
            'Jewellery: silver, platinum.',
        ],
        'soft_summer' => [
            'Build on greyish blues, dusty pinks and soft greys rather than pure white and black.',
            'Instead of black: graphite or a dark blue-grey.',
            'Pair colours that sit close in tone: tone-on-tone reads best.',
            'Jewellery: silver or white gold, matte rather than shiny.',
        ],
        'soft_autumn' => [
            'Build on warm, muted shades: olive, khaki, warm greige, dusty rose.',
            'Instead of black: chocolate or a dark olive.',
            'Pair neighbouring tones and avoid sharp contrast.',
            'Jewellery: matte gold, bronze.',
        ],
        'true_autumn' => [
            'Choose warm, rich colours: mustard, copper, olive, brick.',
            'Instead of black: dark brown.',
            'White should be cream or ivory.',
            'Jewellery: yellow gold, copper, bronze.',
        ],
        'deep_autumn' => [
            'Near the face: deep warm shades — chocolate, wine, dark olive, copper.',
            'Dark colours suit you, but dark chocolate beats black.',
            'Keep pastels and cool light shades away from the face.',
            'Jewellery: antique gold, bronze.',
        ],
        'deep_winter' => [
            'Black, navy and deep emerald are your base.',
            'Contrast works: pair dark with pure white.',
            'Keep warm earthy shades — mustard, camel — away from the face.',
            'Jewellery: silver, white gold.',
        ],
        'true_winter' => [
            'Clear, cool colours: royal blue, fuchsia, emerald, pure white.',
            'Black and white together is your classic.',
            'Muted and warm shades make the face look tired.',
            'Jewellery: silver, platinum.',
        ],
        'bright_winter' => [
            'Your strongest colours are bright and cool: fuchsia, electric blue, icy pink.',
            'Keep contrast high: light with dark, clear with clear.',
            'Dusty, subdued shades dim the face.',
            'Jewellery: polished silver.',
        ],
    ],

    'groups' => [
        'neutrals' => 'Neutrals',
        'base' => 'Base',
        'accents' => 'Accents',
        'avoid' => 'Less near the face',
    ],

    'group_hints' => [
        'neutrals' => 'What a wardrobe is built on: coats, trousers, bags.',
        'base' => 'Everyday pieces that have a colour without making a statement.',
        'accents' => 'The loud ones, worn near the face.',
        'avoid' => 'Near the face these drain it rather than lift it. Further away — shoes, bags, trousers — they can be worn.',
    ],

    'axes' => [
        'temperature' => 'Temperature',
        'depth' => 'Depth',
        'chroma' => 'Clarity',
        'contrast' => 'Contrast',
    ],

    'axis_ends' => [
        'temperature' => ['cool', 'warm'],
        'depth' => ['light', 'deep'],
        'chroma' => ['muted', 'clear'],
    ],

    'contrast_hint' => 'How far apart this face\'s own colours sit: hair against skin, and the iris against the white of the eye. 0 is everything close together, 1 is wide apart.',

    'axis_not_computed' => 'Not computed: the photo was rejected.',
    'no_season' => 'No season: the engine could not measure enough to place one.',

    // Why a feature was left out of an axis. The engine's own reason slugs.
    'exclusions' => [
        'overexposed' => 'Left out — that zone is blown out, so the camera recorded the ceiling rather than a colour.',
        'dyed_hair' => 'Hair was left out — the quiz says it is dyed, so its colour describes a decision rather than a person.',
        'hair_not_measured' => 'Hair was left out — it could not be measured on this photo.',
        'iris_not_measured' => 'The iris was left out — it could not be measured on this photo.',
        'sclera_not_measured' => 'The white of the eye was left out — it could not be measured on this photo.',
        'skin_not_measured' => 'Skin was left out — it could not be measured on this photo.',
        'skin_too_neutral' => 'The skin hue was left out — the sample is too close to grey for its hue angle to mean anything.',
        'contrast_not_measured' => 'Contrast was left out — there were not two zones to compare.',
    ],

    'confidence' => [
        'margin' => 'Raw gap between the top two scores',
        'from_margin' => 'That gap, through the confidence curve',
        'features_factor' => 'What the missing zones cost',
        'quality_factor' => 'What the photograph cost',
        'final' => 'Confidence',
    ],

];
