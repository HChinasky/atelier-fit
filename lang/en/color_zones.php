<?php

/*
 * Why a measurement zone produced nothing.
 *
 * Keys are the engine's own reasons (ScleraReason in sampling.py, plus the zone
 * builder's two). Shown on the admin page in place of a blank row, because
 * "sclera: —" tells a reviewer nothing about whether to retake the photo, fix
 * a threshold or fix a mask.
 *
 * Names, not instructions: unlike a reject reason, these are for whoever is
 * checking the engine, and the useful thing is which of several identical-
 * looking failures it was.
 */

return [
    'too_few_pixels' => 'not enough pixels survived the mask',
    'eye_too_small' => 'the eye is too small in the original photo to measure',
    'hair_occluded' => 'headphones, a hat or a band are covering the hair — take them off',
    'overexposed' => 'the camera stopped recording here — the zone is blown out',
    'too_dark' => 'what was measured is too dark to be the white of an eye',
    'too_noisy' => 'the sample spans more than one surface',
    'not_brighter_than_iris' => 'it is no lighter than the iris of its own eye',
    'darker_than_skin' => 'it sits well below the skin around it — lid shadow, not sclera',
    'more_chromatic_than_skin' => 'it carries as much colour as the skin does, so the sample landed on skin',
    'eyes_disagree' => 'the two eyes disagree about the colour',
    'unexplained' => 'not measured, and the engine gave no reason — that is itself a bug worth reporting',
    'unexplained_exclusion' => 'left out of the calculation, and the engine gave no reason — that is itself a bug worth reporting',
];
