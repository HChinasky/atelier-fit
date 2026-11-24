<?php

// The outfit (garment-set) validation gate. Keys match App\Services\Outfit\OutfitRejection.

return [
    'too_few_items' => 'يُرجى اختيار قطعتين على الأقل للإطلالة.',
    'too_many_items' => 'عدد القطع في الإطلالة كبير جدًا. يُرجى اختيار عدد أقل.',
    'dress_must_be_solo' => 'يمكن تجربة الفستان وحده فقط، لا مع قطع أخرى.',
    'duplicate_layer' => 'يُرجى اختيار أنواع مختلفة من القطع: جزء سفلي وجزء علوي مثلًا، لا قطعتين من النوع نفسه.',
    'unsupported_category' => 'لا يمكن إضافة هذه القطعة إلى إطلالة. يُرجى اختيار قطعة أخرى.',
];
