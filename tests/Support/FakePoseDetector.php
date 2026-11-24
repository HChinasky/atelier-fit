<?php

namespace Tests\Support;

use App\Services\Pose\PoseDetectionException;
use App\Services\Pose\PoseDetector;
use App\Services\Pose\PoseResult;
use Closure;

/**
 * Test double for PoseDetector. Returns a preset PoseResult (or throws to
 * simulate an unavailable service) and records the bytes it was handed, so tests
 * can assert what the detector actually saw (e.g. orientation applied, real image
 * bytes rather than a URL) — all without any live pose service.
 */
class FakePoseDetector implements PoseDetector
{
    public ?string $receivedContents = null;

    public int $calls = 0;

    /** @param Closure(string): PoseResult $responder */
    public function __construct(private Closure $responder) {}

    public static function returning(PoseResult $pose): self
    {
        return new self(fn (string $contents): PoseResult => $pose);
    }

    public static function throwing(string $message = 'pose service unavailable'): self
    {
        return new self(function (string $contents) use ($message): PoseResult {
            throw new PoseDetectionException($message);
        });
    }

    public function detect(string $imageContents, string $filename = 'photo.jpg'): PoseResult
    {
        $this->receivedContents = $imageContents;
        $this->calls++;

        return ($this->responder)($imageContents);
    }
}
