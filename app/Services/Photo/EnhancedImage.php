<?php

namespace App\Services\Photo;

/**
 * A photo after appearance enhancement (9.5): the processed bytes, their format,
 * and which steps the service actually applied (reported back via the response so
 * the pipeline can stay observable without inspecting pixels). Dimensions are
 * unchanged from the input by contract, so they are not re-carried here.
 */
final readonly class EnhancedImage
{
    public function __construct(
        public string $contents,
        public string $format,
        public bool $lightingApplied = false,
        public bool $segmentApplied = false,
    ) {}
}
