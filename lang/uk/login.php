<?php

/*
 * Signing in: the landing page "/", the e-mail and code steps it shares with
 * the overlays, the provider buttons and the server's answers to them.
 */

return [
    'title' => 'Atelier Fit — Вхід',

    // The landing page frames the two steps as a sign-in.
    'page' => [
        'purpose' => 'Atelier Fit — віртуальна примірочна стилістки Atelier Fit. Завантажте власне фото, оберіть річ із каталогу та подивіться, як вона виглядає на вас. Акаунт Google використовується лише для входу і ніколи для створення чи обробки зображень. Сервіс лише приміряє одяг на ваше власне фото і не створює оголених, сексуальних чи інтимних зображень.',
        'eyebrow' => 'Вхід',
        'title' => 'З поверненням',
        'lede' => 'Введи свій e-mail, і ми надішлемо одноразовий код для входу. Або продовж через Google чи Apple.',
        'cta' => 'Надіслати код',
        'otp_eyebrow' => 'Одноразовий код',
        'otp_lede_before' => 'Ми надіслали шестизначний код для входу на',
        'otp_cta' => 'Підтвердити й увійти',
        'pricing_link' => 'Переглянути тарифи',
        'legal' => 'Продовжуючи, ти погоджуєшся з :terms і підтверджуєш ознайомлення з :privacy.',
        'terms' => 'Умовами використання',
        'privacy' => 'Політикою конфіденційності',
    ],

    // Step one: the address.
    'email' => [
        'eyebrow' => 'Крок 1 з 2',
        'title' => 'Твій e-mail',
        'lede' => 'Надішлемо одноразовий код на твій e-mail, щоб переконатися, що це ти.',
        'placeholder' => 'you@example.com',
        'label' => 'Адреса e-mail',
        'cta' => 'Надіслати код',
        'sending' => 'Надсилаємо…',
        'or' => 'або',
        'continue_with' => 'Продовжити з :provider',
        'invalid' => 'Введи коректну адресу e-mail.',
    ],

    // Step two: the code.
    'otp' => [
        'eyebrow' => 'Крок 2 з 2',
        'title' => 'Введи код',
        'lede_before' => 'Ми надіслали шестизначний код на',
        'lede_after' => '. Якщо за хвилину листа немає, перевір папку «Спам».',
        'blocked_title' => 'Забагато спроб',
        'blocked_text' => 'Задля безпеки спробуй знову через :wait.',
        'blocked_wait' => '3 години',
        'label' => 'Шестизначний код',
        'resend_in' => 'Надіслати код повторно через',
        'resend' => 'Надіслати ще раз',
        'cta' => 'Підтвердити',
        'verifying' => 'Перевіряємо…',
        'invalid' => 'Невірний код.',
        'invalid_last' => 'Невірний код — залишилася 1 спроба.',
        'expired' => 'Термін дії коду минув — запроси новий.',
        'resend_failed' => 'Не вдалося надіслати код повторно. Спробуй ще раз.',
    ],

    // What the server answers (EmailAuthController, SocialAuthController).
    'server' => [
        'not_human' => 'Не вдалося підтвердити, що ти не робот. Онови сторінку й спробуй ще раз.',
        'wait' => 'Зачекай трохи, перш ніж запитувати новий код.',
        'unavailable' => 'Коди тимчасово недоступні. Спробуй за кілька хвилин.',
        'send_failed' => 'Не вдалося надіслати код. Перевір адресу й спробуй ще раз.',
        'expired' => 'Термін дії коду минув. Запроси новий.',
        'social_failed' => 'Вхід не завершився. Спробуй ще раз.',
        'social_no_email' => ':provider не передав адресу e-mail. Спробуй ще раз або увійди через e-mail.',
    ],
];
