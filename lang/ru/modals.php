<?php

/*
 * The membership overlays (partials/modals): the guest gate, the paywall,
 * checkout, and the credit toast.
 */

return [
    'gate' => [
        'eyebrow' => 'Личное приглашение',
        'title' => 'Понравился образ?',
        'lede' => 'Твои :free уже использованы. Войди и получи :more.',
        'free' => '2 бесплатные примерки',
        'more' => 'ещё 4 в подарок',
        'email' => 'Продолжить с e-mail',
    ],
    'paywall' => [
        'title' => 'Бесплатные<br>примерки закончились',
        'lede' => 'Выбери тариф, чтобы продолжить примерять.',
        'cta' => 'Подробнее о тарифах',
        'later' => 'Может, позже',
    ],
    'payment' => [
        'eyebrow' => 'Оплата',
        'per_month' => '/мес',
        'apple_pay' => 'Оплатить через Apple Pay',
        'or_card' => 'или картой',
        'card_field' => 'Защищённое поле карты',
        'cta' => 'Оплатить AED 99 / месяц',
        'cancel' => 'Отменить можно в любой момент в личном кабинете.',
    ],
    'toast' => 'Использован 1 кредит · :left',
    'toast_left' => 'осталось :count',
];
