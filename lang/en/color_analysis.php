<?php

/*
 * Why the colour engine could not use a photo, said in a way somebody can act
 * on. Keys are the engine's own RejectReason slugs (see
 * services/color-engine/src/color_engine/models.py) — they are a contract, so
 * never rename one here without changing it there.
 *
 * Every message names the thing to DO. "Low light" tells a person nothing;
 * "move nearer a window" tells them where to stand. A reason we cannot give
 * advice for is a reason we should not be showing.
 */

return [

    // --- the photo itself ---------------------------------------------------

    'low_resolution' => 'This photo is too small. Send the original from your camera roll rather than one that has been through a messenger.',
    'unsupported_format' => 'We cannot read this file. A JPEG, PNG, WebP or HEIC photo will work.',
    'file_too_large' => 'This file is too big. Send the photo as it came off the camera, without upscaling it.',

    // --- finding a face -----------------------------------------------------

    'no_face' => 'We could not find a face in this photo. Take a photo of your face, looking at the camera.',
    'multiple_faces' => 'There is more than one person here. Use a photo of just yourself.',
    'face_too_small' => 'Your face is too small in the frame. Come closer, so your head and shoulders fill most of it.',
    'face_not_frontal' => 'Your head is turned away. Look straight at the camera, with your chin level.',

    // --- measuring it -------------------------------------------------------

    'insufficient_skin' => 'We could not see enough skin to measure. Pull your hair back off your forehead and cheeks, and take off glasses.',
    'low_light' => 'This photo is too dark to measure colour in. Stand facing a window in daylight, with the light on your face and not behind you.',
    'uneven_light' => 'The light is falling from one side, so half your face is in shadow. Stand facing a window, with the light on you straight on.',
    'overexposed' => 'The light is too bright, or the flash has burnt out part of your face. Take the photo in soft daylight, out of direct sun and with the flash off.',
    'color_cast' => 'The light here is too strongly coloured to correct — a lamp, a screen or a coloured wall. Take the photo in daylight, away from coloured light.',
    'makeup_filter' => 'This photo looks retouched. Use an unedited photo, with no beauty filter and no make-up.',

];
