<?php

namespace App\Services\Photo;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\Image\Drivers\Imagick\Driver as ImagickDriver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\DriverInterface;
use Intervention\Image\Interfaces\EncodedImageInterface;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * Intervention implementation. Works in memory; logs no image content. Tunables
 * come from config('photo.output') (target aspect + neutral pad colour) and the
 * encoding reuses config('photo.normalization').
 */
class InterventionPhotoAspectFitter implements PhotoAspectFitter
{
    public function enabled(): bool
    {
        return $this->targetAspect() !== null;
    }

    public function fit(string $normalizedContents, ?CropBox $box, string $format): string
    {
        $target = $this->targetAspect();
        if ($target === null) {
            return $normalizedContents;
        }

        $manager = new ImageManager($this->driver());

        try {
            $image = $manager->read($normalizedContents);
        } catch (Throwable $e) {
            throw new PhotoCropException('Could not decode image for aspect fitting.');
        }

        $w = $image->width();
        $h = $image->height();
        if ($w <= 0 || $h <= 0) {
            return $normalizedContents;
        }

        // The person region in pixels (whole image when there is no crop box).
        $boxW = $box ? max(1.0, $box->width * $w) : (float) $w;
        $boxH = $box ? max(1.0, $box->height * $h) : (float) $h;
        $cx = $box ? ($box->x * $w + $boxW / 2) : $w / 2;
        $cy = $box ? ($box->y * $h + $boxH / 2) : $h / 2;

        // Target rectangle: the smallest box of the target aspect that still
        // contains the person region — i.e. expand the short side, never shrink.
        if (($boxW / $boxH) < $target) {
            $tw = (int) round($boxH * $target);
            $th = (int) round($boxH);
        } else {
            $tw = (int) round($boxW);
            $th = (int) round($boxW / $target);
        }
        $tw = max(1, $tw);
        $th = max(1, $th);

        // Centre it on the person; this may spill outside the image.
        $tl = (int) round($cx - $tw / 2);
        $tt = (int) round($cy - $th / 2);

        // The part that actually exists in the photo (real pixels).
        $rx = max(0, $tl);
        $ry = max(0, $tt);
        $rw = min($w, $tl + $tw) - $rx;
        $rh = min($h, $tt + $th) - $ry;
        if ($rw <= 0 || $rh <= 0) {
            return $normalizedContents;
        }

        // If the target rectangle fits entirely inside the photo we just crop real
        // pixels; otherwise crop what exists and place it on a neutral canvas so the
        // missing margins are padded (never the person).
        $real = $image->crop($rw, $rh, $rx, $ry);

        if ($rw === $tw && $rh === $th) {
            $out = $real;
        } else {
            $out = $manager->create($tw, $th)->fill($this->padColor());
            $out->place($real, 'top-left', $rx - $tl, $ry - $tt);
        }

        return (string) $this->encode($out, $format);
    }

    /**
     * Target aspect (width / height) from config, or null when unset/invalid
     * (fitting disabled). Accepts "W:H", "W/H", or a decimal.
     */
    private function targetAspect(): ?float
    {
        $raw = trim((string) config('photo.output.aspect', ''));
        if ($raw === '') {
            return null;
        }

        foreach ([':', '/'] as $sep) {
            if (str_contains($raw, $sep)) {
                [$a, $b] = array_pad(explode($sep, $raw, 2), 2, '');
                $a = (float) $a;
                $b = (float) $b;

                return ($a > 0 && $b > 0) ? $a / $b : null;
            }
        }

        $value = (float) $raw;

        return $value > 0 ? $value : null;
    }

    private function padColor(): string
    {
        return (string) config('photo.output.pad_color', '#f0f0f0');
    }

    private function encode(ImageInterface $image, string $format): EncodedImageInterface
    {
        $quality = (int) config('photo.normalization.quality', 90);

        return match (strtolower($format)) {
            'png' => $image->toPng(),
            'webp' => $image->toWebp(quality: $quality),
            default => $image->toJpeg(quality: $quality),
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
