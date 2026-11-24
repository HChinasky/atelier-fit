<?php

namespace Database\Factories;

use App\Models\Season;
use App\Models\SeasonColor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SeasonColor>
 */
class SeasonColorFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'season_id' => Season::factory(),
            'hex' => strtoupper(fake()->hexColor()),
            'lab_l' => fake()->randomFloat(2, 15, 92),
            'lab_a' => fake()->randomFloat(2, -40, 60),
            'lab_b' => fake()->randomFloat(2, -50, 60),
            'group' => SeasonColor::GROUP_ACCENTS,
            'is_recommended' => true,
            'sort' => fake()->numberBetween(0, 5),
            'is_draft' => true,
            'source' => SeasonColor::SOURCE_GENERATOR,
        ];
    }

    public function group(string $group): static
    {
        return $this->state(fn () => [
            'group' => $group,
            'is_recommended' => $group !== SeasonColor::GROUP_AVOID,
        ]);
    }

    /**
     * A colour a person chose. The seeder must never touch one of these.
     */
    public function stylist(): static
    {
        return $this->state(fn () => [
            'source' => SeasonColor::SOURCE_STYLIST,
            'is_draft' => false,
        ]);
    }
}
