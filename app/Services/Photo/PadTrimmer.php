<?php

namespace App\Services\Photo;

use Intervention\Image\Colors\Rgb\Colorspace as RgbColorspace;
use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DriverInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * Takes the aspect fitter's padding back off a finished look.
 *
 * Before a try-on, a photo narrower (or wider) than the engine's 3:4 is
 * padded with the neutral pad colour so the person is never cropped
 * (InterventionPhotoAspectFitter). The engine keeps those margins, so the
 * result showed grey bands at its sides. This trims every edge row/column
 * that is almost entirely that colour — the person and the photo's own
 * background are left alone, since only pad-coloured edges go.
 *
 * Best effort and never throws: anything it cannot decode, an image with no
 * such margins, or a trim that would leave less than MIN_KEEP of a side
 * comes back as the original bytes.
 */
class PadTrimmer
{
    /** Per-channel distance from the pad colour that still counts as pad. */
    private const TOLERANCE = 14;

    /** Share of an edge line's samples that must be pad for the line to go. */
    private const PAD_SHARE = 0.96;

    /** Never keep less than this share of the width or height. */
    private const MIN_KEEP = 0.4;

    /** Samples taken along a line (rows or columns are not read pixel by pixel). */
    private const SAMPLES = 120;

    /** Whether there is padding to undo at all: the fitter is on. */
    public function enabled(): bool
    {
        return trim((string) config('photo.output.aspect', '')) !== '';
    }

    /** The image without its pad-coloured margins, as PNG; or the input unchanged. */
    public function trim(string $bytes): string
    {
        if (! $this->enabled() || $bytes === '') {
            return $bytes;
        }

        try {
            $image = (new ImageManager($this->driver()))->read($bytes);
            $pad = $this->padRgb();
            if ($pad === null) {
                return $bytes;
            }

            $w = $image->width();
            $h = $image->height();

            $left = 0;
            while ($left < $w && $this->isPadColumn($image, $left, $h, $pad)) {
                $left++;
            }
            $right = $w - 1;
            while ($right > $left && $this->isPadColumn($image, $right, $h, $pad)) {
                $right--;
            }
            $top = 0;
            while ($top < $h && $this->isPadRow($image, $top, $left, $right, $pad)) {
                $top++;
            }
            $bottom = $h - 1;
            while ($bottom > $top && $this->isPadRow($image, $bottom, $left, $right, $pad)) {
                $bottom--;
            }

            $keepW = $right - $left + 1;
            $keepH = $bottom - $top + 1;

            if ($keepW === $w && $keepH === $h) {
                return $bytes;
            }
            if ($keepW < $w * self::MIN_KEEP || $keepH < $h * self::MIN_KEEP) {
                return $bytes;
            }

            return (string) $image->crop($keepW, $keepH, $left, $top)->toPng();
        } catch (Throwable) {
            return $bytes;
        }
    }

    /** @param  array{int, int, int}  $pad */
    private function isPadColumn(ImageInterface $image, int $x, int $h, array $pad): bool
    {
        $step = max(1, intdiv($h, self::SAMPLES));
        $total = 0;
        $hits = 0;
        for ($y = 0; $y < $h; $y += $step) {
            $total++;
            $hits += $this->isPad($image, $x, $y, $pad) ? 1 : 0;
        }

        return $total > 0 && $hits / $total >= self::PAD_SHARE;
    }

    /** @param  array{int, int, int}  $pad */
    private function isPadRow(ImageInterface $image, int $y, int $from, int $to, array $pad): bool
    {
        $step = max(1, intdiv($to - $from + 1, self::SAMPLES));
        $total = 0;
        $hits = 0;
        for ($x = $from; $x <= $to; $x += $step) {
            $total++;
            $hits += $this->isPad($image, $x, $y, $pad) ? 1 : 0;
        }

        return $total > 0 && $hits / $total >= self::PAD_SHARE;
    }

    /** @param  array{int, int, int}  $pad */
    private function isPad(ImageInterface $image, int $x, int $y, array $pad): bool
    {
        [$r, $g, $b] = $image->pickColor($x, $y)->convertTo(new RgbColorspace)->toArray();

        return abs($r - $pad[0]) <= self::TOLERANCE
            && abs($g - $pad[1]) <= self::TOLERANCE
            && abs($b - $pad[2]) <= self::TOLERANCE;
    }

    /** @return array{int, int, int}|null */
    private function padRgb(): ?array
    {
        $hex = ltrim(trim((string) config('photo.output.pad_color', '#f0f0f0')), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (preg_match('/^[0-9a-fA-F]{6}$/', $hex) !== 1) {
            return null;
        }

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private function driver(): DriverInterface
    {
        return match (strtolower((string) config('photo.normalization.driver', 'gd'))) {
            'imagick' => new ImagickDriver,
            default => new GdDriver,
        };
    }
}
