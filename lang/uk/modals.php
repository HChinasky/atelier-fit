<?php

/*
 * The membership overlays (partials/modals): the guest gate, the paywall,
 * checkout, and the credit toast.
 */

return [
    'gate' => [
        'eyebrow' => 'Особисте запрошення',
        'title' => 'Сподобався образ?',
        'lede' => 'Твої :free вже використано. Увійди й отримай :more.',
        'free' => '2 безкоштовні примірки',
        'more' => 'ще 4 у подарунок',
        'email' => 'Продовжити з e-mail',
    ],
    'paywall' => [
        'title' => 'Безкоштовні<br>примірки закінчилися',
        'lede' => 'Обери тариф, щоб і далі приміряти.',
        'cta' => 'Детальніше про тарифи',
        'later' => 'Можливо, пізніше',
    ],
    'payment' => [
        'eyebrow' => 'Оплата',
        'per_month' => '/міс',
        'apple_pay' => 'Оплатити через Apple Pay',
        'or_card' => 'або карткою',
        'card_field' => 'Захищене поле картки',
        'cta' => 'Оплатити AED 99 / місяць',
        'cancel' => 'Скасувати можна будь-коли в особистому кабінеті.',
    ],
    'toast' => 'Використано 1 кредит · :left',
    'toast_left' => 'залишилось :count',
];
