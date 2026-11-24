<?php

namespace App\Services\Outfit;

use App\Models\Product;

/**
 * The outcome of validating a chosen set of garments (OutfitValidator).
 *
 *  - VALID:   `products` holds the set re-ordered into wearing order (base layer
 *             first), ready to chain. `reason` is null.
 *  - REJECTED: `reason` says why the set is not a valid outfit (shown to the user
 *             as a 422, no fal call). `products` is empty.
 *
 * `fullBody` (valid only) tells the caller whether the set needs a full-height
 * photo — true when it contains a layer that reaches the legs — so the SINGLE
 * Phase-9 pass on the original photo validates/crops for the right framing.
 */
final readonly class OutfitPlan
{
    /**
     * @param  list<Product>  $products  ordered base-layer-first (valid only)
     */
    private function __construct(
        public bool $valid,
        public array $products = [],
        public bool $fullBody = false,
        public ?OutfitRejection $reason = null,
    ) {}

    /**
     * @param  list<Product>  $orderedProducts
     */
    public static function valid(array $orderedProducts, bool $fullBody): self
    {
        return new self(valid: true, products: $orderedProducts, fullBody: $fullBody);
    }

    public static function rejected(OutfitRejection $reason): self
    {
        return new self(valid: false, reason: $reason);
    }

    /**
     * The category that drives the single Phase-9 pass: the bottom-most layer in
     * the set (the one furthest down the body). Passing a full-body category when
     * the set reaches the legs makes the validator/cropper keep full height.
     */
    public function representativeCategory(): string
    {
        return $this->products[0]->category;
    }
}
