<?php

/*
 * Plan copy. The plan names, prices and order stay in MembershipController;
 * the words around them are here, keyed by the plan's key.
 */

return [
    // /pricing (the older prototype's tierData).
    'tiers' => [
        'fashionista' => [
            'tokens' => '20 AI try-ons per month',
            'features' => ['Standard image generation', 'Access to the product catalogue'],
        ],
        'shopaholic' => [
            'tokens' => '50 AI try-ons per month',
            'features' => ['Everything in Fashionista', 'Save your looks', 'Try-on history'],
        ],
        'couture' => [
            'tokens' => '150 AI try-ons per month',
            'features' => ['Everything in Shopaholic', 'Digital Wardrobe', 'Curated capsules and trend books'],
        ],
        'vip' => [
            'tokens' => '300 AI try-ons per month',
            'features' => ['Everything in Couture', 'Priority generation', 'Monthly written review of one saved look: up to 10 minutes, one reply'],
        ],
    ],

    // The dashboard's plan cards (Figma Desk_lk 1430:6601).
    'dashboard' => [
        'free' => [
            'tagline' => 'See how it works',
            'tryons' => '3 free AI try-ons to see yourself in a new look and test Atelier Fit.',
            'cta' => 'Try for free',
        ],
        'fashionista' => [
            'price' => 'AED 39 / month',
            'tagline' => 'Try it and create new looks now and then',
            'tryons' => '20 AI try-ons a month, standard image generation and access to the product catalogue.',
            'cta' => 'Choose Fashionista',
        ],
        'shopaholic' => [
            'price' => 'AED 99 / month',
            'tagline' => 'For regular styling and comparing looks',
            'tryons' => '50 AI try-ons a month, everything in Fashionista, plus saving looks and try-on history.',
            'cta' => 'Choose Shopaholic',
        ],
        'couture' => [
            'price' => 'AED 269 / month',
            'tagline' => 'Build a wardrobe and plan looks for any occasion',
            'tryons' => '150 AI try-ons a month, everything in Shopaholic, a Digital Wardrobe, curated capsules and trend books.',
            'cta' => 'Choose Couture',
        ],
        'vip' => [
            'price' => 'AED 749 / month',
            'tagline' => 'Everything in Couture, with priority',
            'tryons' => '300 AI try-ons a month, everything in Couture and priority generation. Once a month the stylist reviews one saved look in writing: up to 10 minutes and one reply with recommendations.',
            'cta' => 'Choose Diva VIP',
        ],
    ],
];
