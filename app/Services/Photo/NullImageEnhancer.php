<?php

namespace App\Services\Photo;

/**
 * No-op enhancer: returns the photo unchanged. Bound when both enhancement steps
 * are disabled (or in environments without the pose service), so the pipeline is
 * byte-identical to the pre-9.5 behaviour and makes no HTTP call.
 */
class NullImageEnhancer implements PhotoEnhancer
{
    public function enhance(string $imageContents, string $format = 'jpeg'): EnhancedImage
    {
        return new EnhancedImage(contents: $imageContents, format: $format);
    }
}
