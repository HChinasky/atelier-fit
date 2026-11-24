<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Default state: a valid, NOT-yet-verified product (mirrors the schema
     * default — only the verified() state opts a product into the catalog).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $externalId = (string) fake()->unique()->ean13();
        $brand = fake()->randomElement(['Zara', 'Mango', 'Massimo Dutti', 'H&M']);
        $productName = fake()->words(3, true);
        // Decimal string (e.g. "1493.07") built from integer cents — no float.
        $cents = fake()->numberBetween(2000, 500000);
        $price = sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
        $category = fake()->randomElement(['upper_body', 'lower_body', 'dresses']);
        // The feed's own word for the category, the way a real feed files it.
        $feedCategory = match ($category) {
            'upper_body' => 'Топы и блузы',
            'lower_body' => 'Юбки',
            default => 'Платья',
        };
        $imageUrl = fake()->imageUrl(640, 800, 'fashion');
        $shopUrl = fake()->url();

        return [
            'external_id' => $externalId,
            'brand_name' => $brand,
            'product_name' => $productName,
            'price_aed' => $price,
            'image_flat_url' => $imageUrl,
            'shop_url' => $shopUrl,
            'category' => $category,
            'feed_category' => $feedCategory,
            'flat_bg_verified' => false,
            // Representative raw feed object (nested → exercises the jsonb cast).
            'raw' => [
                'id' => $externalId,
                'brand' => $brand,
                'title' => $productName,
                'price' => ['amount' => $price, 'currency' => 'AED'],
                'images' => ['flat' => $imageUrl],
                'url' => $shopUrl,
            ],
        ];
    }

    /**
     * Photo is usable for try-on → product is eligible for the catalog.
     */
    public function verified(): static
    {
        return $this->state(fn (array $attributes) => [
            'flat_bg_verified' => true,
        ]);
    }

    /**
     * A Google Merchant item with its own <description>.
     */
    public function described(?string $text = null): static
    {
        return $this->state(fn (array $attributes) => [
            'raw' => array_merge($attributes['raw'] ?? [], ['description' => $text ?? fake()->sentence(12)]),
        ]);
    }

    /**
     * Extra photos beyond the try-on one, as a Google Merchant feed lists
     * them (<additional_image_link>, repeated).
     *
     * @param  list<string>|null  $urls
     */
    public function withGallery(?array $urls = null): static
    {
        return $this->state(fn (array $attributes) => [
            'raw' => array_merge($attributes['raw'] ?? [], [
                'additional_image_link' => $urls ?? [fake()->imageUrl(), fake()->imageUrl()],
            ]),
        ]);
    }

    /**
     * Its colour measured by the engine: one colour per [L, a, b, share],
     * largest share first. Defaults to a single deep navy.
     *
     * @param  list<array{0: float, 1: float, 2: float, 3: float}>  $colours
     */
    public function measured(array $colours = [[25.0, 5.0, -30.0, 1.0]]): static
    {
        return $this->state(fn (array $attributes) => [
            'color' => [
                'status' => 'ok',
                'reject_reason' => null,
                'method' => 'background',
                'coverage' => 0.3,
                'colours' => array_map(fn (array $c) => [
                    'L' => $c[0], 'a' => $c[1], 'b' => $c[2],
                    'C' => round(hypot($c[1], $c[2]), 3),
                    'h' => round(fmod(rad2deg(atan2($c[2], $c[1])) + 360, 360), 3),
                    'hex' => '#1B2A4A',
                    'share' => $c[3],
                ], $colours),
                'algo_version' => 'garment-0.1.0',
                'image_url' => $attributes['image_flat_url'] ?? null,
            ],
            'color_status' => 'ok',
            'color_measured_at' => now(),
        ]);
    }

    /** The engine looked at the photo and could not measure it. */
    public function colourRejected(string $reason = 'no_garment'): static
    {
        return $this->state(fn (array $attributes) => [
            'color' => [
                'status' => 'rejected',
                'reject_reason' => $reason,
                'colours' => [],
                'image_url' => $attributes['image_flat_url'] ?? null,
            ],
            'color_status' => 'rejected',
            'color_measured_at' => now(),
        ]);
    }

    /**
     * Photo not usable → kept out of the catalog (explicit counterpart).
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'flat_bg_verified' => false,
        ]);
    }

    public function upperBody(): static
    {
        return $this->ofCategory('upper_body');
    }

    public function lowerBody(): static
    {
        return $this->ofCategory('lower_body');
    }

    public function dresses(): static
    {
        return $this->ofCategory('dresses');
    }

    /** Capsule-only: never offered for try-on. */
    public function shoes(): static
    {
        return $this->ofCategory('shoes')->ofFeedCategory('Shoes');
    }

    /** Capsule-only: never offered for try-on. */
    public function headwear(): static
    {
        return $this->ofCategory('headwear')->ofFeedCategory('Hats');
    }

    /**
     * Pin what the piece is for, as a source set it (keyword parsing by
     * default).
     */
    public function forOccasion(string $occasion, ?string $style = null, string $source = 'parse'): static
    {
        return $this->state(fn (array $attributes) => [
            'occasion' => $occasion,
            'style' => $style,
            'style_source' => $source,
            'style_tagged_at' => now(),
        ]);
    }

    /**
     * Pin the FASHN category.
     */
    public function ofCategory(string $category): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => $category,
        ]);
    }

    /**
     * Pin the category the feed files the product under (the chip source).
     */
    public function ofFeedCategory(?string $feedCategory): static
    {
        return $this->state(fn (array $attributes) => [
            'feed_category' => $feedCategory,
        ]);
    }

    /**
     * Pin the brand (handy for the upcoming brand-filter tests).
     */
    public function brand(string $brand): static
    {
        return $this->state(fn (array $attributes) => [
            'brand_name' => $brand,
        ]);
    }
}
