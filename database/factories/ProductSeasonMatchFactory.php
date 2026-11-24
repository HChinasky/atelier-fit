<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductSeasonMatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductSeasonMatch>
 */
class ProductSeasonMatchFactory extends Factory
{
    /**
     * Default state: a close match to one of the season's colours to wear.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_id' => Product::factory()->verified(),
            'season' => fake()->randomElement(SeasonFactory::SLUGS),
            'suits' => true,
            'score' => fake()->randomFloat(3, 0.4, 1.0),
            'delta_e' => fake()->randomFloat(2, 1.0, 8.0),
            'palette_group' => fake()->randomElement(['neutrals', 'base', 'accents']),
        ];
    }

    /** Nearer the season's colours to avoid than to any it wears. */
    public function clashing(): static
    {
        return $this->state(fn (): array => [
            'suits' => false,
            'score' => 0.0,
            'delta_e' => fake()->randomFloat(2, 15.0, 40.0),
            'palette_group' => null,
        ]);
    }

    /** Matched a neutral or base colour of the season. */
    public function neutral(): static
    {
        return $this->state(fn (): array => ['suits' => true, 'palette_group' => 'neutrals']);
    }

    /** Matched one of the season's accent colours. */
    public function accent(): static
    {
        return $this->state(fn (): array => ['suits' => true, 'palette_group' => 'accents']);
    }

    public function scored(float $score): static
    {
        return $this->state(fn (): array => ['score' => $score]);
    }

    public function forSeason(string $slug): static
    {
        return $this->state(fn (): array => ['season' => $slug]);
    }
}
