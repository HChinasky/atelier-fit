<?php

/*
 * Capsules: ready looks from the catalog in the member's palette, by occasion.
 */

return [

    'occasions' => [
        'office' => 'العمل',
        'evening' => 'المساء',
        'casual' => 'كاجوال',
    ],

    'blurbs' => [
        'office' => 'قطع هادئة ليوم العمل بألوان لوحتك.',
        'evening' => 'قطع للسهرات بألوانك، من القطعة الأساسية حتى الحذاء.',
        'casual' => 'قطع أساسية لكل يوم تتناسق مع بعضها ومعك.',
    ],

    // trans_choice() in Arabic: zero|one|two|few (3–10)|many (11–99)|other.
    'pieces' => ':count قطعة|قطعة واحدة|قطعتان|:count قطع|:count قطعة|:count قطعة',

    'occasion_for' => [
        'office' => 'للعمل',
        'evening' => 'للمساء',
        'casual' => 'لكل يوم',
    ],

    'slots' => [
        'upper_body' => 'الجزء العلوي',
        'lower_body' => 'الجزء السفلي',
        'dresses' => 'فستان / طقم',
        'shoes' => 'حذاء',
        'headwear' => 'غطاء رأس',
    ],

    // Between slot names inside the quotes of 'missing'.
    'slots_glue' => '»، «',

    'slots_lower' => [
        'upper_body' => 'الجزء العلوي',
        'lower_body' => 'الجزء السفلي',
        'dresses' => 'فستان',
        'shoes' => 'حذاء',
        'headwear' => 'غطاء رأس',
    ],

    'accents_glue' => ' و',

    'explain' => [
        'neutral' => 'كل شيء بالألوان المحايدة والأساسية من لوحة «:season» — إطلالة هادئة :occasion.',
        'one_accent' => 'قاعدة محايدة من لوحة «:season» ولون بارز واحد — :accents — :occasion.',
        'two_accents' => 'قاعدة محايدة من لوحة «:season» ولونان بارزان — :accents — :occasion.',
    ],

    'missing' => 'لا يمكننا تنسيق إطلالة :occasion بعد: لا يوجد في الكتالوج قطع بألوانك في قسم «:slots». ستظهر الكبسولات هنا فور توفرها.',
    'no_occasion_pieces' => 'لا يوجد في الكتالوج بعد قطع بألوانك مصممة :occasion — ولا ننسّق مثل هذه الإطلالة من القطع الأساسية وحدها. نحن نضيف قطعًا جديدة إلى الكتالوج.',
    'no_balanced_look' => 'توجد قطع بألوانك :occasion، لكنها معًا لا تشكّل إطلالة متوازنة — الألوان البارزة كثيرة جدًا. نحن نضيف قطعًا جديدة إلى الكتالوج.',

    'ui' => [
        'title' => 'Atelier Fit — الكبسولات',
        'title_one' => 'Atelier Fit — كبسولة «:name»',
        'eyebrow' => 'الكبسولات ودفاتر الصيحات',
        'heading' => 'كبسولات جاهزة',
        'lede' => 'إطلالات متكاملة لكل مناسبة بألوان لوحتك. افتح الكبسولة، وجرّب القطع بالذكاء الاصطناعي، واشترِ ما يعجبك.',
        'locked_title' => 'كبسولات من خبيرة الأزياء',
        'locked_text' => 'مختارات الصيحات متاحة بدءًا من باقة Couture.',
        'locked_cta' => 'الترقية إلى Couture',
        'colour_off' => 'تُنسَّق الكبسولات من لوحة نوع ألوانك. تحليل الألوان غير متاح حاليًا — ستظهر الكبسولات فور عودته.',
        'no_season' => 'تُنسَّق الكبسولات من لوحة نوع ألوانك، ولم يُحدَّد نوع ألوانك بعد. :link — وستظهر هنا إطلالات بألوانك.',
        'no_season_link' => 'ابدأ تحليل الألوان',
        'gathering' => 'ما زلنا ننسّق الإطلالات',
        'colours' => 'ألوان الكبسولة',
        'open' => 'عرض الكبسولة',
        'back' => 'العودة إلى الكبسولات',
        'pieces_heading' => ':count في الكبسولة',
        'favorite' => 'إضافة إلى المفضلة',
        'try_on' => 'تجربة',
        'try_on_unavailable' => 'تجربة هذه القطعة غير متاحة بعد',
        'buy' => 'شراء',
        'ways' => 'كيف ترتديها',
        'ways_sub' => 'إطلالات من قطع هذه الكبسولة، منسّقة حسب لوحة ألوانك.',
        'match' => 'التطابق مع اللوحة — :percent%.',
        'look' => 'الإطلالة :n',
    ],

];
