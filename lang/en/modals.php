<?php

/*
 * The membership overlays (partials/modals): the guest gate, the paywall,
 * checkout, and the credit toast.
 */

return [
    'gate' => [
        'eyebrow' => 'A Private Invitation',
        'title' => 'Loved the look?',
        'lede' => "You've used your :free. Sign in and receive :more.",
        'free' => '2 free try-ons',
        'more' => '4 more, on the house',
        'email' => 'Continue with E-mail',
    ],
    'paywall' => [
        'title' => 'Your complimentary<br>try-ons are over',
        'lede' => 'Choose a plan to keep trying on.',
        'cta' => 'Compare plans in detail',
        'later' => 'Maybe later',
    ],
    'payment' => [
        'eyebrow' => 'Checkout',
        'per_month' => '/mo',
        'apple_pay' => 'Pay with Apple Pay',
        'or_card' => 'or pay with card',
        'card_field' => 'Secure card field',
        'cta' => 'Pay AED 99 / month',
        'cancel' => 'You can cancel anytime from your account.',
    ],
    'toast' => '1 token used · :left',
    'toast_left' => ':count left',
];
