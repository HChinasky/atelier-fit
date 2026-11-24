<?php

/*
 * What the try-on endpoints answer (GenerationGate, the generation
 * controllers, the status resource).
 */

return [
    'signup_required' => 'انتهت التجارب المجانية. سجّل الدخول واحصل على :count تجارب إضافية.',
    'daily_limit' => 'وصلت إلى الحد اليومي للتجارب. يُرجى المحاولة غدًا.',
    'credits_exhausted' => 'نفد رصيدك. قم بترقية باقتك لمواصلة التجربة.',
    'unavailable' => 'خدمة التجربة غير متاحة مؤقتًا. يُرجى المحاولة لاحقًا.',
    'photo_service_down' => 'خدمة معالجة الصور غير متاحة مؤقتًا. يُرجى المحاولة لاحقًا.',
    'lead_missing' => 'لم يتم العثور على بيانات التواصل. يُرجى إدخالها مجددًا.',
    'failed' => 'عذرًا، تعذّر تصميم الإطلالة. جرّب صورة أخرى أو قطعة أخرى.',

    // Field names in validation messages.
    'attributes' => [
        'lead_id' => 'بيانات التواصل',
        'product_id' => 'القطعة',
        'photo_key' => 'الصورة',
    ],

];
