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
 * Intervention Image implementation. Works entirely in memory (no temp files,
 * nothing written to disk, photo never leaves the server) and logs no image
 * content. All tunables come from config('photo.normalization').
 */
class InterventionPhotoNormalizer implements PhotoNormalizer
{
    public function normalize(string $imageContents): NormalizedImage
    {
        $manager = new ImageManager($this->driver());

        try {
            $image = $manager->read($imageContents);
        } catch (Throwable $e) {
            // No photo bytes in the message — privacy.
            throw new PhotoNormalizationException('Could not decode image for normalization.');
        }

        // 1) EXIF orientation FIRST: rotate the pixels to the visually correct
        //    orientation. Phones store sideways pixels + an orientation flag; if
        //    we skip this the photo is "on its side" for pose detection and FASHN.
        $image->orient();

        // 2) Downscale to fit the max box, preserving aspect ratio. scaleDown
        //    never upscales, so small photos pass through untouched and nothing
        //    is ever stretched/distorted.
        $max = (int) config('photo.normalization.max_dimension', 2000);
        $image->scaleDown(width: $max, height: $max);

        // 3) Re-encode to the target format. Encoding produces a fresh image
        //    WITHOUT the EXIF orientation flag, so it can never be rotated twice
        //    downstream.
        [$encoded, $format] = $this->encode($image);

        return new NormalizedImage(
            contents: (string) $encoded,
            width: $image->width(),
            height: $image->height(),
            format: $format,
        );
    }

    /**
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
