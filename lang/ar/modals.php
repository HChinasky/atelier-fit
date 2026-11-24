<?php

/*
 * The membership overlays (partials/modals): the guest gate, the paywall,
 * checkout, and the credit toast.
 */

return [
    'gate' => [
        'eyebrow' => 'دعوة خاصة',
        'title' => 'هل أعجبتك الإطلالة؟',
        'lede' => 'لقد استخدمت :free. سجّل الدخول واحصل على :more.',
        'free' => 'تجربتيك المجانيتين',
        'more' => '4 تجارب إضافية هدية',
        'email' => 'المتابعة بالبريد الإلكتروني',
    ],
    'paywall' => [
        'title' => 'انتهت تجاربك<br>المجانية',
        'lede' => 'اختر باقة لمواصلة التجربة.',
        'cta' => 'تفاصيل الباقات',
        'later' => 'ربما لاحقًا',
    ],
    'payment' => [
        'eyebrow' => 'الدفع',
        'per_month' => '/شهريًا',
        'apple_pay' => 'الدفع عبر Apple Pay',
        'or_card' => 'أو الدفع بالبطاقة',
        'card_field' => 'حقل بطاقة آمن',
        'cta' => 'ادفع 99 AED / شهريًا',
        'cancel' => 'يمكنك الإلغاء في أي وقت من حسابك.',
    ],
    'toast' => 'تم استخدام رصيد واحد · :left',
    'toast_left' => 'المتبقي :count',
];
