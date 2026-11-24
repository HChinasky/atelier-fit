<?php

/* Mail the site sends. */

return [
    'login_code' => [
        'subject' => ':code — твой код для входа в Atelier Fit',
        'title' => 'Твой код для входа',
        'text_heading' => 'Твой код для входа в Atelier Fit: :code',
        'valid' => 'Код действует :minutes мин. Если это не твой запрос, просто проигнорируй это письмо — без кода никто не сможет войти.',
    ],
];
