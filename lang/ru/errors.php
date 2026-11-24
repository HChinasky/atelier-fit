<?php

/*
 * What a JSON request hears when the framework itself stops it (App\Exceptions\LocalizedErrors).
 */

return [
    'unauthenticated' => 'Сессия завершилась. Войди ещё раз.',
    'forbidden' => 'Нет доступа.',
    'not_found' => 'Не найдено.',
    'method_not_allowed' => 'Такой запрос не поддерживается.',
    'page_expired' => 'Страница устарела. Обнови её и попробуй ещё раз.',
    'too_many_requests' => 'Слишком много попыток. Подожди минутку и попробуй ещё раз.',
    'server_error' => 'Что-то пошло не так. Попробуй ещё раз чуть позже.',
    'unavailable' => 'Сервис временно недоступен. Попробуй чуть позже.',

    // The error page (resources/views/errors/minimal.blade.php).
    'home' => 'На главную',
];
