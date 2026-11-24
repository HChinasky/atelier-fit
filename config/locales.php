<?php

/*
 * The interface languages. Adding one is a lang/<code>/ folder with the same
 * files and keys as lang/en (TranslationParityTest holds them to it) plus a
 * row here; nothing else in the code lists languages.
 *
 * `name` is the language in its own words (what the switcher shows),
 * `short` the two-letter mark beside it, `dir` the text direction for
 * <html dir> — Arabic is "rtl".
 */

return [
    'supported' => [
        'uk' => ['name' => 'Українська', 'short' => 'UA', 'dir' => 'ltr'],
        'en' => ['name' => 'English', 'short' => 'EN', 'dir' => 'ltr'],
        'ru' => ['name' => 'Русский', 'short' => 'RU', 'dir' => 'ltr'],
        'ar' => ['name' => 'العربية', 'short' => 'AR', 'dir' => 'rtl'],
    ],

    // How long a guest's choice outlives the session, in minutes (a year).
    'cookie_minutes' => 60 * 24 * 365,
];
