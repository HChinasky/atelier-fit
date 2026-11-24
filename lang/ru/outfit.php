<?php

// The outfit (garment-set) validation gate. Keys match App\Services\Outfit\OutfitRejection.

return [
    'too_few_items' => 'Выбери, пожалуйста, хотя бы две вещи для образа.',
    'too_many_items' => 'Слишком много вещей в образе. Выбери, пожалуйста, меньше.',
    'dress_must_be_solo' => 'Платье можно примерить только отдельно — не в комплекте с другими вещами.',
    'duplicate_layer' => 'Выбери, пожалуйста, разные типы вещей: например, низ и верх — не две одинаковые.',
    'unsupported_category' => 'Эту вещь нельзя добавить в образ. Выбери, пожалуйста, другую.',
];
