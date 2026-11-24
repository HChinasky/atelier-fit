<?php

/*
 * What the try-on endpoints answer (GenerationGate, the generation
 * controllers, the status resource).
 */

return [
    'signup_required' => 'Бесплатные примерки закончились. Войди — и получишь ещё :count.',
    'daily_limit' => 'Дневной лимит примерок исчерпан. Попробуй завтра.',
    'credits_exhausted' => 'Кредиты закончились. Обнови тариф, чтобы продолжить примерки.',
    'unavailable' => 'Сервис примерки временно недоступен. Попробуй позже.',
    'photo_service_down' => 'Сервис обработки фото временно недоступен. Попробуй, пожалуйста, позже.',
    'lead_missing' => 'Контакт не найден. Пожалуйста, оставь его ещё раз.',
    'failed' => 'К сожалению, не удалось создать образ. Попробуй другое фото или другую вещь.',

    // Field names in validation messages.
    'attributes' => [
        'lead_id' => 'контакт',
        'product_id' => 'вещь',
        'photo_key' => 'фото',
    ],

];
