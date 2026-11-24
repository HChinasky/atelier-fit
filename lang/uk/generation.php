<?php

/*
 * What the try-on endpoints answer (GenerationGate, the generation
 * controllers, the status resource).
 */

return [
    'signup_required' => 'Безкоштовні примірки вичерпано. Увійдіть — і отримаєте ще :count примірок.',
    'daily_limit' => 'Ви вичерпали денний ліміт примірок. Спробуйте завтра.',
    'credits_exhausted' => 'Токени закінчилися. Оновіть план, щоб продовжити примірки.',
    'unavailable' => 'Сервіс примірки тимчасово недоступний. Спробуйте пізніше.',
    'photo_service_down' => 'Сервіс обробки фото тимчасово недоступний. Спробуйте, будь ласка, пізніше.',
    'lead_missing' => 'Контакт не знайдено. Будь ласка, залиште його ще раз.',
    'failed' => 'На жаль, не вдалося створити образ. Спробуйте інше фото або іншу річ.',

    // Field names in validation messages.
    'attributes' => [
        'lead_id' => 'контакт',
        'product_id' => 'річ',
        'photo_key' => 'фото',
    ],

];
