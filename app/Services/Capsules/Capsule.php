<?php

namespace App\Services\Capsules;

use App\Models\Product;

/**
 * One look: its pieces by slot, how well they sit in the member's palette
 * (0..1, the mean of the pieces' colour-match scores) and why it was put
 * together.
 */
final class Capsule
{
    public const KIND_SEPARATES = 'separates';

    public const KIND_DRESS = 'dress';

    /** Display order of the slots. */
    public const SLOTS = ['upper_body', 'lower_body', 'dresses', 'shoes', 'headwear'];

    /**
     * @param  array<string, Product>  $pieces  slot → product (with match_score / match_group)
     */
    public function __construct(
        public readonly string $occasion,
        public readonly string $kind,
        public readonly array $pieces,
        public readonly float $score,
        public readonly int $accents,
        public readonly string $explanation,
    ) {}

    /** The colour-match score as a whole percentage. */
    public function percent(): int
    {
        return (int) round($this->score * 100);
    }

    /** @return list<int> */
    public function productIds(): array
    {
        return array_values(array_map(fn (Product $p) => $p->id, $this->pieces));
    }
}
