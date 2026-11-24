<?php

namespace App\Services\Pose;

/**
 * The main detected person: a bounding box plus the body landmarks, keyed by
 * name (e.g. "left_ankle") for direct lookup by consumers.
 */
final readonly class Person
{
    /**
     * @param  array<string, Landmark>  $landmarks  Keyed by landmark name.
     */
    public function __construct(
        public BoundingBox $boundingBox,
        public array $landmarks = [],
    ) {}

    /**
     * Look up a single landmark by name, or null if absent.
     */
    public function landmark(string $name): ?Landmark
    {
        return $this->landmarks[$name] ?? null;
    }
}
