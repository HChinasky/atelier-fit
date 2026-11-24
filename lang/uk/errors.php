<?php

/*
 * What a JSON request hears when the framework itself stops it (bootstrap/app.php):
 * a signed-out session, a missing record, an expired form, a throttle. The
 * error pages' own titles are the framework's JSON keys, in lang/uk.json.
 */

return [
    'unauthenticated' => 'Сесія завершилася. Увійди ще раз.',
    'forbidden' => 'Немає доступу.',
    'not_found' => 'Не знайдено.',
    'method_not_allowed' => 'Такий запит не підтримується.',
    'page_expired' => 'Сторінка застаріла. Онови її й спробуй ще раз.',
    'too_many_requests' => 'Забагато спроб. Зачекай хвилинку й спробуй ще раз.',
    'server_error' => 'Щось пішло не так. Спробуй ще раз трохи згодом.',
    'unavailable' => 'Сервіс тимчасово недоступний. Спробуй трохи згодом.',

    // The error page (resources/views/errors/minimal.blade.php).
    'home' => 'На головну',
];
