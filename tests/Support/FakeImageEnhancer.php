<?php

namespace Tests\Support;

use App\Services\Photo\EnhancedImage;
use App\Services\Photo\PhotoEnhancementException;
use App\Services\Photo\PhotoEnhancer;
use Closure;

/**
 * Test double for PhotoEnhancer. Returns a preset EnhancedImage (or throws to
 * simulate the service being unavailable) and records the bytes it was handed, so
 * tests can assert the enhancer saw the normalized photo and the cropper saw the
 * enhancer's output — all without any live pose/enhancement service.
 */
class FakeImageEnhancer implements PhotoEnhancer
{
    public ?string $receivedContents = null;

    public int $calls = 0;

    /** @param Closure(string, string): EnhancedImage $responder */
    public function __construct(private Closure $responder) {}

    /**
     * Returns the given bytes (defaults to a marker so a test can prove the crop
     * ran on the enhancer's output rather than the original).
     */
    public static function returning(string $contents): self
    {
        return new self(fn (string $in, string $format): EnhancedImage => new EnhancedImage(
            contents: $contents,
            format: $format,
            lightingApplied: true,
            segmentApplied: true,
        ));
    }

    public static function throwing(string $message = 'enhancement service unavailable'): self
    {
        return new self(function (string $in, string $format) use ($message): EnhancedImage {
            throw new PhotoEnhancementException($message);
        });
    }

    public function enhance(string $imageContents, string $format = 'jpeg'): EnhancedImage
    {
        $this->receivedContents = $imageContents;
        $this->calls++;

        return ($this->responder)($imageContents, $format);
    }
}
