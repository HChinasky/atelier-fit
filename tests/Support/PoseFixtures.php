<?php

namespace Tests\Support;

use App\Services\Pose\BoundingBox;
use App\Services\Pose\Landmark;
use App\Services\Pose\Person;
use App\Services\Pose\PoseResult;

/**
 * Hand-built PoseResult / image fixtures shared across the preprocessing tests,
 * so pose-shaped test data lives in one place (and avoids global Pest-helper
 * name clashes between test files). Coordinates are expressed as fractions of the
 * frame so a fixture matches whatever image size the test normalizes to.
 */
class PoseFixtures
{
    /** A flat-colour JPEG of the given size, in memory (no disk, no network). */
    public static function jpeg(int $width, int $height): string
    {
        $im = imagecreatetruecolor($width, $height);
        imagefill($im, 0, 0, imagecolorallocate($im, 130, 170, 210));
        ob_start();
        imagejpeg($im, null, 92);
        $bytes = ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    /** Frontal, full-height subject (head + confident ankles) inside the frame. */
    public static function fullBody(int $w = 1000, int $h = 1500): PoseResult
    {
        return self::pose($w, $h, [
            self::lm('nose', 0.99, 0.50 * $w, 0.08 * $h),
            self::lm('left_shoulder', 0.98, 0.64 * $w, 0.23 * $h),
            self::lm('right_shoulder', 0.98, 0.36 * $w, 0.23 * $h),
            self::lm('left_hip', 0.95, 0.60 * $w, 0.53 * $h),
            self::lm('right_hip', 0.95, 0.40 * $w, 0.53 * $h),
            self::lm('left_ankle', 0.93, 0.59 * $w, 0.93 * $h),
            self::lm('right_ankle', 0.92, 0.41 * $w, 0.93 * $h),
        ]);
    }

    /**
     * Same frontal torso, but the ankles are extrapolated off-frame at near-zero
     * confidence — a waist-up shot. Passes a top, fails a dress/lower_body.
     */
    public static function waistUp(int $w = 1000, int $h = 1500): PoseResult
    {
        return self::pose($w, $h, [
            self::lm('nose', 0.99, 0.50 * $w, 0.08 * $h),
            self::lm('left_shoulder', 0.98, 0.64 * $w, 0.23 * $h),
            self::lm('right_shoulder', 0.98, 0.36 * $w, 0.23 * $h),
            self::lm('left_hip', 0.95, 0.60 * $w, 0.53 * $h),
            self::lm('right_hip', 0.95, 0.40 * $w, 0.53 * $h),
            self::lm('left_ankle', 0.02, 0.59 * $w, 1.20 * $h),
            self::lm('right_ankle', 0.02, 0.41 * $w, 1.20 * $h),
        ]);
    }

    /** The empty signature the Null detector / a fail-open outage produces. */
    public static function empty(): PoseResult
    {
        return new PoseResult(0, 0, 0, null, 0.5);
    }

    private static function lm(string $name, float $confidence, float $x, float $y): Landmark
    {
        return new Landmark($name, $x, $y, 0.0, 0.0, 0.0, $confidence);
    }

    /**
     * @param  array<Landmark>  $landmarks
     */
    private static function pose(int $w, int $h, array $landmarks): PoseResult
    {
        $keyed = [];
        foreach ($landmarks as $lm) {
            $keyed[$lm->name] = $lm;
        }

        return new PoseResult(1, $w, $h, new Person(new BoundingBox(0.0, 0.0, (float) $w, (float) $h), $keyed), 0.5);
    }
}
