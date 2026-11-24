<?php

/*
 * What a JSON request hears when the framework itself stops it (App\Exceptions\LocalizedErrors).
 */

return [
    'unauthenticated' => 'انتهت جلستك. يُرجى تسجيل الدخول مجددًا.',
    'forbidden' => 'ليس لديك صلاحية الوصول.',
    'not_found' => 'غير موجود.',
    'method_not_allowed' => 'هذا الطلب غير مدعوم.',
    'page_expired' => 'انتهت صلاحية الصفحة. حدّثها وحاول مجددًا.',
    'too_many_requests' => 'محاولات كثيرة جدًا. يُرجى الانتظار دقيقة والمحاولة مجددًا.',
    'server_error' => 'حدث خطأ ما. يُرجى المحاولة مجددًا بعد قليل.',
    'unavailable' => 'الخدمة غير متاحة مؤقتًا. يُرجى المحاولة بعد قليل.',

    // The error page (resources/views/errors/minimal.blade.php).
    'home' => 'العودة إلى البداية',
];
