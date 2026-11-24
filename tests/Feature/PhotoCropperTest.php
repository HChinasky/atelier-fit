<?php

use App\Services\Photo\InterventionPhotoCropper;
use App\Services\Photo\PhotoCropException;
use App\Services\Photo\PhotoCropper;
use App\Services\Pose\BoundingBox;
use App\Services\Pose\Landmark;
use App\Services\Pose\Person;
use App\Services\Pose\PoseResult;
use Illuminate\Support\Facades\Log;

/*
 * Person-aware cropping (9.4). Runs against hand-built PoseResult fixtures and
 * GD-generated photos — no pose service, no FASHN, nothing on the network. The
 * crop box is built from RELIABLE landmarks (confident AND in-frame); a confident
 * but off-frame point must NOT stretch the crop (the right_pinky case).
 */

const IMG_W = 1000;
const IMG_H = 1500;

function cropper(): PhotoCropper
{
    return app(InterventionPhotoCropper::class);
}

function cropJpeg(int $width = IMG_W, int $height = IMG_H): string
{
    $im = imagecreatetruecolor($width, $height);
    imagefill($im, 0, 0, imagecolorallocate($im, 130, 170, 210));
    ob_start();
    imagejpeg($im, null, 92);
    $bytes = ob_get_clean();
    imagedestroy($im);

    return $bytes;
}

function cropLandmark(string $name, float $confidence, float $x = 0.0, float $y = 0.0): Landmark
{
    return new Landmark($name, $x, $y, 0.0, 0.0, 0.0, $confidence);
}

/**
 * @param  array<Landmark>  $landmarks
 */
function cropPose(array $landmarks, int $personCount = 1, int $width = IMG_W, int $height = IMG_H): PoseResult
{
    $keyed = [];
    foreach ($landmarks as $lm) {
        $keyed[$lm->name] = $lm;
    }

    $person = $personCount >= 1
        ? new Person(new BoundingBox(0.0, 0.0, (float) $width, (float) $height), $keyed)
        : null;

    return new PoseResult($personCount, $width, $height, $person, 0.5);
}

/**
 * Confident frontal landmarks for a full-body subject: head (nose), shoulders,
 * hips, ankles — all comfortably inside a 1000x1500 frame.
 *
 * @return array<Landmark>
 */
function fullBodyLandmarks(): array
{
    return [
        cropLandmark('nose', 0.99, x: 500, y: 120),
        cropLandmark('left_shoulder', 0.98, x: 640, y: 350),
        cropLandmark('right_shoulder', 0.98, x: 360, y: 350),
        cropLandmark('left_hip', 0.95, x: 600, y: 800),
        cropLandmark('right_hip', 0.95, x: 400, y: 800),
        cropLandmark('left_ankle', 0.93, x: 590, y: 1400),
        cropLandmark('right_ankle', 0.92, x: 410, y: 1400),
    ];
}

/**
 * The audited real case: a 600x900 full-body photo whose topmost face landmark is
 * the nose at y=104 (MediaPipe reports no crown point above it). Body (shoulders +
 * hips) spans x 218..382 (~164px wide), feet at y=892. With the OLD defaults this
 * cropped to a 196px-wide column whose top sliced the head at y=41.
 *
 * @return array<Landmark>
 */
function tallPhotoLandmarks(): array
{
    return [
        cropLandmark('nose', 0.9999, x: 300, y: 104),
        cropLandmark('right_shoulder', 0.99, x: 218, y: 210),
        cropLandmark('left_shoulder', 0.99, x: 382, y: 210),
        cropLandmark('right_hip', 0.97, x: 235, y: 470),
        cropLandmark('left_hip', 0.97, x: 365, y: 470),
        cropLandmark('right_ankle', 0.95, x: 255, y: 892),
        cropLandmark('left_ankle', 0.95, x: 345, y: 892),
    ];
}

it('crops a full-body dress photo to include the head and feet, with margins', function () {
    $pose = cropPose(fullBodyLandmarks());

    $result = cropper()->crop(cropJpeg(), $pose, 'dresses');

    // Body box: x 360..640, y 120(face)..1400(ankles); torso top (shoulders) y=350.
    // Padding (defaults): head span = 350-120 = 230; crown = 1.1*230 = 253 (beats
    // the 8%-of-height floor of 102.4); side = 50% of width(280) = 140; bottom = 5%
    // of height(1280) = 64.
    //   left = 360-140 = 220, right = 640+140 = 780      -> width  560
    //   top  = max(0, 120-253) = 0, bottom = 1400+64 = 1464 -> height 1464
    expect($result->cropped)->toBeTrue()
        ->and($result->width)->toBe(560)
        ->and($result->height)->toBe(1464);

    // Stays inside the image and the metadata matches the encoded pixels.
    [$w, $h] = getimagesizefromstring($result->contents);
    expect($w)->toBe($result->width)
        ->and($h)->toBe($result->height)
        ->and($result->width)->toBeLessThanOrEqual(IMG_W)
        ->and($result->height)->toBeLessThanOrEqual(IMG_H);

    // Head kept (top padding reaches above the nose at y=120) and feet kept
    // (bottom padding reaches below the ankles at y=1400): the 1446px-tall crop
    // spans from y=18 to y=1464, enclosing both.
    expect($result->height)->toBeGreaterThan(1400 - 120);
});

it('does NOT stretch the crop to a confident but OUT-OF-FRAME hand point (right_pinky)', function () {
    // The audited case: right_pinky passes the confidence threshold (0.55 >= 0.5)
    // but its y is BELOW the image bottom (1700 > 1500) -> off-frame. A naive
    // bbox crop would balloon to the edge; the reliable-points filter drops it.
    $offFramePinky = cropLandmark('right_pinky', 0.55, x: 980, y: 1700);

    $withPinky = cropper()->crop(cropJpeg(), cropPose([...fullBodyLandmarks(), $offFramePinky]), 'dresses');
    $withoutPinky = cropper()->crop(cropJpeg(), cropPose(fullBodyLandmarks()), 'dresses');

    // Identical crop: the off-frame point changed nothing.
    expect($withPinky->width)->toBe($withoutPinky->width)
        ->and($withPinky->height)->toBe($withoutPinky->height)
        // And it is the torso-driven width (560), NOT stretched toward x=980.
        ->and($withPinky->width)->toBe(560);
});

it('DOES widen the crop for a confident IN-FRAME hand point (filter is by frame, not by body part)', function () {
    // Same pinky confidence, but now inside the frame (x=900, y=900). A visible
    // arm legitimately widens the crop so it is not cut off.
    $inFramePinky = cropLandmark('right_pinky', 0.55, x: 900, y: 900);

    $widened = cropper()->crop(cropJpeg(), cropPose([...fullBodyLandmarks(), $inFramePinky]), 'dresses');
    $base = cropper()->crop(cropJpeg(), cropPose(fullBodyLandmarks()), 'dresses');

    expect($widened->width)->toBeGreaterThan($base->width)
        ->and($widened->width)->toBeLessThanOrEqual(IMG_W);
});

it('extends an upper_body crop to the legs when they are visibly in frame (full height kept)', function () {
    // Full-body photo with confident, in-frame ankles. A top on a FULL-LENGTH photo
    // must NOT be sliced at the hips (the audited 9.x regression: result cropped at
    // the hips though the person stood full height). With the legs in frame the crop
    // runs to the feet, exactly like the dress crop.
    $pose = cropPose(fullBodyLandmarks());

    $upper = cropper()->crop(cropJpeg(), $pose, 'upper_body');
    $dress = cropper()->crop(cropJpeg(), $pose, 'dresses');

    // Bottom anchor = ankles (y=1400), not hips (y=800): the legs are kept.
    expect($upper->cropped)->toBeTrue()
        ->and($upper->height)->toBe(1464)
        // Full height kept — well past the hips, all the way to the feet.
        ->and($upper->height)->toBeGreaterThan(1400 - 120)
        // Same frame as the dress on this full-length photo.
        ->and($upper->height)->toBe($dress->height)
        ->and($upper->width)->toBe($dress->width);
});

it('crops an upper_body waist-up photo to the hips (no legs in frame)', function () {
    // Genuine waist-up shot: the ankles are extrapolated off-frame at near-zero
    // confidence, so no leg point is reliable. The crop then stops at the hips
    // (legs are neither visible nor needed) instead of guessing a lower bound.
    $pose = cropPose([
        cropLandmark('nose', 0.99, x: 500, y: 120),
        cropLandmark('left_shoulder', 0.98, x: 640, y: 350),
        cropLandmark('right_shoulder', 0.98, x: 360, y: 350),
        cropLandmark('left_hip', 0.95, x: 600, y: 800),
        cropLandmark('right_hip', 0.95, x: 400, y: 800),
        cropLandmark('left_ankle', 0.02, x: 590, y: 1800),
        cropLandmark('right_ankle', 0.02, x: 410, y: 1800),
    ]);

    $upper = cropper()->crop(cropJpeg(), $pose, 'upper_body');

    // Bottom anchor = hips (y=800): top=0, bottom = 800 + 5%*680(34) = 834.
    expect($upper->cropped)->toBeTrue()
        ->and($upper->height)->toBe(834)
        ->and($upper->height)->toBeLessThan(1400 - 120);
});

it('extends an upper_body crop to the KNEES when only the knees are in frame', function () {
    // Thighs-up shot: knees are reliable but the ankles are off-frame. The crop
    // keeps the visible legs (down to the knees) rather than cutting at the hips.
    $withKnees = cropPose([
        cropLandmark('nose', 0.99, x: 500, y: 120),
        cropLandmark('left_shoulder', 0.98, x: 640, y: 350),
        cropLandmark('right_shoulder', 0.98, x: 360, y: 350),
        cropLandmark('left_hip', 0.95, x: 600, y: 800),
        cropLandmark('right_hip', 0.95, x: 400, y: 800),
        cropLandmark('left_knee', 0.90, x: 590, y: 1100),
        cropLandmark('right_knee', 0.90, x: 410, y: 1100),
    ]);

    $result = cropper()->crop(cropJpeg(), $withKnees, 'upper_body');

    // Bottom anchor = knees (y=1100), past the hips at 800.
    expect($result->cropped)->toBeTrue()
        ->and($result->height)->toBeGreaterThan(834);
});

it('falls back to the untouched photo when reliable landmarks are insufficient', function () {
    // Only the face is confident; the torso points are below threshold. There is
    // no trustworthy torso to crop around -> return the photo byte-for-byte.
    $bytes = cropJpeg();
    $pose = cropPose([
        cropLandmark('nose', 0.99, x: 500, y: 250),
        cropLandmark('left_shoulder', 0.20, x: 640, y: 350),
        cropLandmark('right_shoulder', 0.15, x: 360, y: 350),
        cropLandmark('left_hip', 0.10, x: 600, y: 800),
        cropLandmark('right_hip', 0.10, x: 400, y: 800),
    ]);

    $result = cropper()->crop($bytes, $pose, 'dresses');

    expect($result->cropped)->toBeFalse()
        ->and($result->width)->toBe(IMG_W)
        ->and($result->height)->toBe(IMG_H)
        // Nothing was re-encoded or cut: the head is safe because the photo is intact.
        ->and($result->contents)->toBe($bytes);
});

it('falls back for a full-body category when no reliable foot anchor is present', function () {
    // Confident frontal torso but the ankles are off-frame/low-confidence: we
    // cannot find the bottom of a dress without guessing, so we do not crop.
    $bytes = cropJpeg();
    $pose = cropPose([
        cropLandmark('nose', 0.99, x: 500, y: 120),
        cropLandmark('left_shoulder', 0.98, x: 640, y: 350),
        cropLandmark('right_shoulder', 0.98, x: 360, y: 350),
        cropLandmark('left_hip', 0.95, x: 600, y: 800),
        cropLandmark('right_hip', 0.95, x: 400, y: 800),
        cropLandmark('left_ankle', 0.02, x: 590, y: 1800),
        cropLandmark('right_ankle', 0.02, x: 410, y: 1800),
    ]);

    $result = cropper()->crop($bytes, $pose, 'dresses');

    expect($result->cropped)->toBeFalse()
        ->and($result->contents)->toBe($bytes);
});

it('keeps the crop inside the image when padding would overflow the edges', function () {
    // Body spans nearly the whole frame; padding would push outside -> clamp.
    $pose = cropPose([
        cropLandmark('nose', 0.99, x: 500, y: 10),
        cropLandmark('left_shoulder', 0.98, x: 980, y: 200),
        cropLandmark('right_shoulder', 0.98, x: 20, y: 200),
        cropLandmark('left_hip', 0.95, x: 900, y: 700),
        cropLandmark('right_hip', 0.95, x: 100, y: 700),
        cropLandmark('left_ankle', 0.93, x: 850, y: 1490),
        cropLandmark('right_ankle', 0.92, x: 150, y: 1490),
    ]);

    $result = cropper()->crop(cropJpeg(), $pose, 'dresses');

    expect($result->cropped)->toBeTrue()
        ->and($result->width)->toBeLessThanOrEqual(IMG_W)
        ->and($result->height)->toBeLessThanOrEqual(IMG_H)
        ->and($result->width)->toBeGreaterThan(0)
        ->and($result->height)->toBeGreaterThan(0);

    [$w, $h] = getimagesizefromstring($result->contents);
    expect($w)->toBe($result->width)->and($h)->toBe($result->height);
});

it('does not distort: the crop is a sub-rectangle at 1:1 scale (aspect not forced)', function () {
    $pose = cropPose(fullBodyLandmarks());

    $result = cropper()->crop(cropJpeg(), $pose, 'dresses');

    // The crop dimensions equal the computed rectangle (560x1464), so no resize
    // happened, and the resulting aspect ratio differs from the source 1000:1500
    // — proving nothing was stretched to fit the original frame.
    expect($result->width)->toBe(560)
        ->and($result->height)->toBe(1464)
        ->and($result->width / $result->height)->not->toBe(IMG_W / IMG_H);
});

it('passes an empty pose result through untouched (fail-open)', function () {
    $bytes = cropJpeg();
    $pose = new PoseResult(0, 0, 0, null, 0.5);

    $result = cropper()->crop($bytes, $pose, 'dresses');

    expect($result->cropped)->toBeFalse()
        ->and($result->contents)->toBe($bytes);
});

it('throws PhotoCropException on undecodable input', function () {
    expect(fn () => cropper()->crop('this is not an image', cropPose(fullBodyLandmarks()), 'dresses'))
        ->toThrow(PhotoCropException::class);
});

it('calibration: keeps the whole head and a natural width on the audited 600x900 photo', function () {
    // Same photo and pose for both runs; only the padding config differs. Bottom
    // padding clamps to the image edge (y=900) in both runs, so cropTop = 900-height.
    $pose = cropPose(tallPhotoLandmarks(), width: 600, height: 900);
    $photo = cropJpeg(600, 900);

    // OLD defaults: no crown allowance, tight 10% side fraction. Reproduces the bug.
    config([
        'photo.cropping.padding.crown' => 0.0,
        'photo.cropping.padding.top' => 0.08,
        'photo.cropping.padding.side' => 0.10,
    ]);
    $old = cropper()->crop($photo, $pose, 'dresses');
    $oldTop = 900 - $old->height;

    // NEW (shipped) defaults: crown allowance + 50% side fraction.
    config([
        'photo.cropping.padding.crown' => 1.1,
        'photo.cropping.padding.top' => 0.08,
        'photo.cropping.padding.side' => 0.5,
    ]);
    $new = cropper()->crop($photo, $pose, 'dresses');
    $newTop = 900 - $new->height;

    // OLD reproduces the audited numbers: a 196px-wide column whose top cuts into
    // the head at y=41 (below the crown).
    expect($old->width)->toBe(196)
        ->and($oldTop)->toBe(41);

    // NEW fixes BOTH defects:
    //  - width no longer collapses: 328px vs 196px;
    //  - the top reaches y=0 — above the topmost face landmark (nose y=104) — so the
    //    crown/hair are inside the frame instead of being sliced at y=41.
    expect($new->width)->toBe(328)
        ->and($newTop)->toBe(0)
        ->and($new->width)->toBeGreaterThan($old->width)
        ->and($newTop)->toBeLessThan($oldTop)
        // Top edge above the highest face point: the whole head is kept.
        ->and($newTop)->toBeLessThan(104);
});

it('does not collapse the crop width into a narrow column (sides scale with body width)', function () {
    // Body width ~164px (shoulders/hips). With the side fraction at 0.5 the crop is
    // ~2x the body, keeping real side air — never the ~1.2x column the old fraction
    // produced (which would be ~196px here).
    $pose = cropPose(tallPhotoLandmarks(), width: 600, height: 900);

    $result = cropper()->crop(cropJpeg(600, 900), $pose, 'dresses');

    $bodyW = 382 - 218; // 164

    expect($result->width)->toBeGreaterThan((int) round($bodyW * 1.5))
        ->and($result->width)->toBeLessThanOrEqual(600);
});

it('exposes the crop box as fractions of the source image (for the Before/After slider)', function () {
    // Full-body dress crop computed above: pixel rect [220, 0, 560, 1464] on a
    // 1000x1500 image -> fractions x=0.22, y=0, w=0.56, h=0.976, aspect=560/1464.
    $result = cropper()->crop(cropJpeg(), cropPose(fullBodyLandmarks()), 'dresses');

    expect($result->box)->not->toBeNull();

    $box = $result->box->toArray();

    expect($box['x'])->toBe(220 / 1000)
        ->and($box['y'])->toBe(0.0)
        ->and($box['width'])->toBe(560 / 1000)
        ->and($box['height'])->toBe(1464 / 1500)
        ->and($box['aspect'])->toBe(560 / 1464)
        // Fractions are normalized to 0..1 and the box stays inside the image.
        ->and($box['x'] + $box['width'])->toBeLessThanOrEqual(1.0)
        ->and($box['y'] + $box['height'])->toBeLessThanOrEqual(1.0);
});

it('exposes no crop box on the safe fallback (nothing to frame)', function () {
    // Too few reliable points -> pass-through fallback, so there is no crop region.
    $pose = cropPose([
        cropLandmark('nose', 0.99, x: 500, y: 250),
        cropLandmark('left_shoulder', 0.20, x: 640, y: 350),
        cropLandmark('right_shoulder', 0.15, x: 360, y: 350),
    ]);

    $result = cropper()->crop(cropJpeg(), $pose, 'dresses');

    expect($result->cropped)->toBeFalse()
        ->and($result->box)->toBeNull();
});

it('does not log image content during cropping', function () {
    $logged = [];
    Log::listen(function ($message) use (&$logged) {
        $logged[] = $message->message.' '.json_encode($message->context);
    });

    cropper()->crop(cropJpeg(), cropPose(fullBodyLandmarks()), 'dresses');

    expect($logged)->toBeEmpty();
});
