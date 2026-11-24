<?php

namespace App\Services\Capsules;

use App\Models\Product;

/**
 * The looks for one occasion, or why there are none: a required slot with
 * nothing in the member's colours for it ('missing_slots'), no piece made
 * for an occasion that needs one ('no_occasion_pieces'), or pieces that
 * exist but never make a balanced look together ('no_balanced_look').
 */
final class CapsuleSet
{
    public const NO_PIECES = 'missing_slots';

    public const NO_OCCASION_PIECES = 'no_occasion_pieces';

    public const NO_BALANCED_LOOK = 'no_balanced_look';

    /**
     * @param  list<Capsule>  $capsules
     * @param  list<string>  $missingSlots
     */
    public function __construct(
        public readonly string $occasion,
        public readonly array $capsules,
        public readonly ?string $reason = null,
        public readonly array $missingSlots = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->capsules === [];
    }

    /**
     * Every piece the looks are made of, once each, in slot order (tops
     * first): the capsule's wardrobe, as its page lists it.
     *
     * @return list<Product>
     */
    public function pieces(): array
    {
        $pieces = [];
        foreach (Capsule::SLOTS as $slot) {
            foreach ($this->capsules as $capsule) {
                $product = $capsule->pieces[$slot] ?? null;
                if ($product !== null) {
                    $pieces[$product->id] ??= $product;
                }
            }
        }

        return array_values($pieces);
    }

    /**
     * The pieces' measured main colours, each once, at most $limit: the
     * swatches under the capsule's cover.
     *
     * @return list<string>
     */
    public function colours(int $limit = 4): array
    {
        $colours = [];
        foreach ($this->pieces() as $product) {
            if (($hex = $product->mainHex()) !== null) {
                $colours[$hex] = $hex;
            }
        }

        return array_slice(array_values($colours), 0, $limit);
    }
}
