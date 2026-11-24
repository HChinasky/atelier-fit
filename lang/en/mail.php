<?php

/* Mail the site sends. */

return [
    'login_code' => [
        'subject' => ':code is your Atelier Fit sign-in code',
        'title' => 'Your sign-in code',
        'text_heading' => 'Your Atelier Fit sign-in code: :code',
        'valid' => 'It is valid for :minutes minutes. If you did not request it, you can ignore this message — nobody can sign in without the code.',
    ],
];
