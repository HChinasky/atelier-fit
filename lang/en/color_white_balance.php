<?php

/*
 * Why the colour engine did not correct for the light.
 *
 * Keys are the engine's own WhiteBalanceReason slugs — a contract. "method:
 * none" on its own cannot be read: it covers a deployment that never wanted
 * correction, a frame whose eyes were unusable, and one whose background was
 * too dark to estimate from. Only one of those is about the photograph, and a
 * page that shows the first as though it were the second sends somebody off to
 * retake a photo that was fine.
 */

return [
    'off' => 'Off — the samples are exactly as measured.',
    'not_reached' => 'On, but the photo was refused before it ran.',
    'applied' => 'On, and applied.',
    'disabled' => 'Off — the samples are exactly as measured.',
    'sclera_unusable' => 'On, but not applied: the whites of the eyes could not be measured, so there was no reference to correct against.',
    'sclera_unusable_and_scene_unusable' => 'On, but not applied: the whites of the eyes could not be measured and the background gave nothing usable either — too dark, too uniform, or too small.',
    'cast_suspected_without_an_estimate' => 'On, but not applied: the frame looks colour-cast and there was no reliable estimate to undo it with. The samples are as measured.',
];
