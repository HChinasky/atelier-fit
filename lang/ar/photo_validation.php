<?php

// Reasons a photo was rejected by the suitability gate. Keys match App\Services\Photo\ValidationFailureReason.

return [
    'no_person' => 'لم نجد شخصًا في هذه الصورة. يُرجى تحميل صورة لك.',
    'multiple_people' => 'يُرجى تحميل صورة يظهر فيها شخص واحد فقط.',
    'not_full_body' => 'يُرجى تحميل صورة بالطول الكامل مع مواجهة الكاميرا، لنتمكن من تجربة هذه القطعة عليك.',
    'not_frontal' => 'يُرجى مواجهة الكاميرا في الصورة — لا الوقوف جانبيًا أو بالظهر.',
    'face_only' => 'يُرجى تحميل صورة يظهر فيها جسمك، لا وجهك فقط.',
];
