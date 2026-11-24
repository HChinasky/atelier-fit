<?php

namespace App\Services\Photo;

use App\Services\Pose\Landmark;
use App\Services\Pose\Person;
use App\Services\Pose\PoseResult;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DriverInterface;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * Intervention Image implementation of person-aware cropping (9.4). Works in
 * memory (no temp files, photo never leaves the server) and logs no image
 * content. Output is re-encoded with the SAME driver/format/quality as
 * normalization (9.3) so the preprocessing pipeline stays consistent.
 *
 * The crop box is built from named, RELIABLE landmarks — not the raw bbox — so a
 * confident-but-off-frame point cannot blow it up (see reliablePoints()).
 */
class InterventionPhotoCropper implements PhotoCropper
{
    /** Top-edge anchors: the head. Their min-y keeps the crown from being cut. */
    private const HEAD = ['nose', 'left_eye', 'right_eye', 'left_ear', 'right_ear'];

    /**
     * Structural torso points. We require >= 2 reliable ones to trust that there
     * really is a frontal torso to crop around (not a stray confident point).
     */
    private const TORSO = ['left_shoulder', 'right_shoulder', 'left_hip', 'right_hip'];

    /**
     * Horizontal-extent anchors: torso PLUS the arm/hand chain AND the legs. A
     * visible (in-frame, confident) arm or wide stance legitimately widens the crop
     * so it is not cut; an off-frame point (the right_pinky case) is dropped by the
     * reliability filter and therefore never stretches the box to the image edge.
     */
    private const SIDES = [
        'left_shoulder', 'right_shoulder', 'left_hip', 'right_hip',
        'left_elbow', 'right_elbow', 'left_wrist', 'right_wrist',
        'left_pinky', 'right_pinky', 'left_index', 'right_index',
        'left_thumb', 'right_thumb',
        'left_knee', 'right_knee', 'left_ankle', 'right_ankle',
        'left_heel', 'right_heel', 'left_foot_index', 'right_foot_index',
    ];

    /**
     * Bottom-edge anchor that MUST be present for full-body categories: a real
     * foot point. A maxi dress hem cannot be guessed from hips or knees, so without
     * a foot we bail to the safe fallback rather than crop the legs off.
     */
    private const FEET = [
        'left_ankle', 'right_ankle', 'left_heel', 'right_heel',
        'left_foot_index', 'right_foot_index',
    ];

    /**
     * Every reliably-visible LOWER-BODY point — knees plus the feet. The bottom
     * edge is taken as the lowest of these whenever the legs are in frame, so a
     * full-length photo keeps its legs (the 9.4 regression: a top was being sliced
     * at the hips even though the ankles were clearly visible).
     */
    private const LEGS = [
        'left_knee', 'right_knee',
        'left_ankle', 'right_ankle', 'left_heel', 'right_heel',
        'left_foot_index', 'right_foot_index',
    ];

    /** Fallback bottom-edge anchor when no legs are in frame (a waist-up shot). */
    private const HIPS = ['left_hip', 'right_hip'];

    public function crop(string $imageContents, PoseResult $pose, string $category): CroppedImage
    {
        $manager = new ImageManager($this->driver());

        try {
            $image = $manager->read($imageContents);
        } catch (Throwable $e) {
            // No photo bytes in the message — privacy.
            throw new PhotoCropException('Could not decode image for cropping.');
        }

        $imgW = $image->width();
        $imgH = $image->height();

        $box = $this->cropBox($pose, $category, $imgW, $imgH);

        // FALLBACK: too few reliable points to crop confidently. Return the photo
        // byte-for-byte untouched — better to ship an uncropped photo than to cut
        // off the head or legs. No recompression either.
        if ($box === null) {
            return new CroppedImage($imageContents, $imgW, $imgH, cropped: false);
        }

        [$left, $top, $width, $height] = $box;

        // The crop rectangle as fractions of the SOURCE image — handed to the
        // front-end so it can frame the "Before" image to this exact region.
        $cropBox = CropBox::fromPixels($box, $imgW, $imgH);

        // Pure crop: a rectangle is lifted out at 1:1 scale. Nothing is resized or
        // stretched, so the subject's proportions are preserved.
        $image->crop($width, $height, $left, $top);

        [$encoded] = $this->encode($image);

        return new CroppedImage(
            contents: (string) $encoded,
            width: $image->width(),
            height: $image->height(),
            cropped: true,
            box: $cropBox,
        );
    }

    /**
     * Crop rectangle as [left, top, width, height] in image pixels, or null when
     * there are too few reliable landmarks to crop safely.
     *
     * @return array{0:int,1:int,2:int,3:int}|null
     */
    private function cropBox(PoseResult $pose, string $category, int $imgW, int $imgH): ?array
    {
        if ($pose->isEmpty() || $pose->mainPerson === null || $imgW <= 0 || $imgH <= 0) {
            return null;
        }

        $person = $pose->mainPerson;
        $threshold = (float) config('photo.cropping.landmark_confidence', 0.5);

        $head = $this->reliablePoints($person, self::HEAD, $threshold, $imgW, $imgH);
        $torso = $this->reliablePoints($person, self::TORSO, $threshold, $imgW, $imgH);
        $sides = $this->reliablePoints($person, self::SIDES, $threshold, $imgW, $imgH);
        $hips = $this->reliablePoints($person, self::HIPS, $threshold, $imgW, $imgH);
        $legs = $this->reliablePoints($person, self::LEGS, $threshold, $imgW, $imgH);

        // Bottom edge, by category:
        //  - full-body (dresses/lower_body): a real FOOT point is MANDATORY — a hem
        //    cannot be guessed from hips/knees, so bail to the fallback otherwise.
        //    The edge then runs to the lowest visible lower-body point.
        //  - upper_body: keep the FULL height when the legs are in frame (a
        //    full-length photo must not be sliced at the hips), but fall back to the
        //    hips for a genuine waist-up shot.
        if ($this->requiresFullBody($category)) {
            $feet = $this->reliablePoints($person, self::FEET, $threshold, $imgW, $imgH);
            $bottom = $feet === [] ? [] : array_merge($legs, $hips);
        } else {
            $bottom = $legs !== [] ? array_merge($legs, $hips) : $hips;
        }

        // Need a head anchor (top edge), a real torso (>= 2 structural points for a
        // trustworthy width), horizontal anchors, and a category bottom anchor.
        // Missing any of these means we cannot place the box without risking a cut
        // body, so we bail to the safe fallback.
        if ($head === [] || count($torso) < 2 || $sides === [] || $bottom === []) {
            return null;
        }

        // Topmost RELIABLE face point — nose/eye/ear level. MediaPipe gives no
        // crown/hair point, so this sits well BELOW the top of the head.
        $topFaceY = min(array_map(static fn (Landmark $l): float => $l->y, $head));
        // Top of the torso (shoulders when present, else hips): the lower anchor of
        // the face->shoulder span we use to size the head.
        $torsoTopY = min(array_map(static fn (Landmark $l): float => $l->y, $torso));
        $bottomY = max(array_map(static fn (Landmark $l): float => $l->y, $bottom));
        $leftX = min(array_map(static fn (Landmark $l): float => $l->x, $sides));
        $rightX = max(array_map(static fn (Landmark $l): float => $l->x, $sides));

        $bodyW = max(1.0, $rightX - $leftX);
        $bodyH = max(1.0, $bottomY - $topFaceY);
        // Head-size proxy: distance from the topmost face point down to the
        // shoulder/torso line. It tracks the head's scale in the frame regardless
        // of how big the person is, which is what the crown allowance scales with.
        $headSpan = max(1.0, $torsoTopY - $topFaceY);

        // TOP: the crown is ABOVE the topmost face landmark by roughly a head, but
        // MediaPipe never reports it. Extend upward by a multiple of the head span
        // so hair/crown plus a little air are enclosed — never cutting the head.
        // The height-fraction term is kept as a floor so the box degrades sanely if
        // the head span is tiny (e.g. a near-frontal head-on shoulders shot).
        $crownPad = (float) config('photo.cropping.padding.crown', 1.1) * $headSpan;
        $topFloor = (float) config('photo.cropping.padding.top', 0.08) * $bodyH;
        $padTop = max($crownPad, $topFloor);
        $padBottom = (float) config('photo.cropping.padding.bottom', 0.05) * $bodyH;
        // SIDES: scale with body width so the subject keeps natural air on the left
        // and right instead of being squeezed into a tight column around the torso.
        $padSide = (float) config('photo.cropping.padding.side', 0.5) * $bodyW;

        // Pad, then clamp to the image: the crop can never fall outside the photo.
        $cropLeft = (int) round(max(0.0, $leftX - $padSide));
        $cropTop = (int) round(max(0.0, $topFaceY - $padTop));
        $cropRight = (int) round(min((float) $imgW, $rightX + $padSide));
        $cropBottom = (int) round(min((float) $imgH, $bottomY + $padBottom));

        $width = $cropRight - $cropLeft;
        $height = $cropBottom - $cropTop;

        if ($width <= 0 || $height <= 0) {
            return null;
        }

        return [$cropLeft, $cropTop, $width, $height];
    }

    /**
     * The named landmarks that are BOTH confident enough AND inside the image.
     *
     * Excluding out-of-frame points is the crux of 9.4: MediaPipe can report a
     * point with passing confidence yet a coordinate beyond the image edge (e.g.
     * right_pinky at confidence ~0.55 with y past the bottom). Such a point would
     * stretch the crop box to the image border — exactly the bug the bbox suffers.
     * Here it is simply dropped, so the box is set only by what is truly visible.
     *
     * @param  array<string>  $names
     * @return array<Landmark>
     */
    private function reliablePoints(Person $person, array $names, float $threshold, int $imgW, int $imgH): array
    {
        $points = [];

        foreach ($names as $name) {
            $lm = $person->landmark($name);

            if ($lm === null || $lm->confidence < $threshold) {
                continue;
            }

            if ($lm->x < 0 || $lm->x > $imgW || $lm->y < 0 || $lm->y > $imgH) {
                continue; // out-of-frame -> excluded from the crop box
            }

            $points[] = $lm;
        }

        return $points;
    }

    private function requiresFullBody(string $category): bool
    {
        return in_array($category, (array) config('photo.cropping.full_body_categories', []), true);
    }

    /**
     * Encode the cropped image, mirroring normalization (9.3) so both steps emit
     * the same format/quality.
     *
     * @return array{0: EncodedImageInterface, 1: string}
     */
    private function encode(ImageInterface $image): array
    {
        $format = strtolower((string) config('photo.normalization.format', 'jpeg'));
        $quality = (int) config('photo.normalization.quality', 90);

        return match ($format) {
            'png' => [$image->toPng(), 'png'],
            'webp' => [$image->toWebp(quality: $quality), 'webp'],
            default => [$image->toJpeg(quality: $quality), 'jpeg'],
        };
    }

    private function driver(): DriverInterface
    {
        return match (strtolower((string) config('photo.normalization.driver', 'gd'))) {
            'imagick' => new ImagickDriver,
            default => new GdDriver,
        };
    }
}
