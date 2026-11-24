<?php

// The outfit (garment-set) validation gate. Keys match
// App\Services\Outfit\OutfitRejection.

return [
    'too_few_items' => 'Please pick at least two pieces for the look.',
    'too_many_items' => 'Too many pieces in the look. Please pick fewer.',
    'dress_must_be_solo' => 'A dress can only be tried on by itself, not together with other pieces.',
    'duplicate_layer' => 'Please pick different kinds of pieces: a bottom and a top, for example, not two of the same.',
    'unsupported_category' => 'This piece cannot be added to a look. Please pick another one.',
];
