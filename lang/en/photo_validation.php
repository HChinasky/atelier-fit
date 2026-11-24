<?php

// User-facing reasons a photo was rejected by the suitability gate (9.2).
// Keys match App\Services\Photo\ValidationFailureReason. The flow (9.5) decides
// how/where to show these; this file just makes the messages translatable.

return [
    'no_person' => "We couldn't find a person in this photo. Please upload a photo of yourself.",
    'multiple_people' => 'Please upload a photo with exactly one person.',
    'not_full_body' => 'Please upload a full-length photo, facing the camera, so we can fit this item on you.',
    'not_frontal' => 'Please face the camera in your photo — not turned to the side or away.',
    'face_only' => 'Please upload a photo that shows your body, not just your face.',
];
