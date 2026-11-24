<?php

/* Why the colour engine did not correct for the light. Keys are the engine's WhiteBalanceReason slugs. */

return [
    'off' => 'متوقف — العينات كما قيست تمامًا.',
    'not_reached' => 'مفعّل، لكن الصورة رُفضت قبل الوصول إلى هذه الخطوة.',
    'applied' => 'مفعّل ومُطبَّق.',
    'disabled' => 'متوقف — العينات كما قيست تمامًا.',
    'sclera_unusable' => 'مفعّل لكنه لم يُطبَّق: تعذّر قياس بياض العينين، فلم يكن هناك مرجع للتصحيح.',
    'sclera_unusable_and_scene_unusable' => 'مفعّل لكنه لم يُطبَّق: تعذّر قياس بياض العينين، ولم تقدّم الخلفية شيئًا مفيدًا أيضًا — مظلمة جدًا أو متجانسة جدًا أو صغيرة جدًا.',
    'cast_suspected_without_an_estimate' => 'مفعّل لكنه لم يُطبَّق: يبدو الإطار مصبوغًا بلون، ولم يتوفر تقدير موثوق لإزالته. العينات كما قيست.',
];
