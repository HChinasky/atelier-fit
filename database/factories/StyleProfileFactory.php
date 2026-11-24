<?php

namespace Database\Factories;

use App\Models\StyleProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StyleProfile>
 */
class StyleProfileFactory extends Factory
{
    /**
     * Default state: a row that exists but has not been through the offer —
     * what the colour page's hair question leaves behind.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'answers' => null,
            'completed_at' => null,
            'skipped_at' => null,
        ];
    }

    /** Every question answered, as the quiz page submits them. */
    public function completed(): static
    {
        return $this->state(fn (): array => [
            'answers' => [
                'natural_hair' => true,
                'metal' => fake()->randomElement(['gold', 'silver', 'both']),
                'tanning' => fake()->randomElement(['burns', 'tans', 'both']),
                'clarity' => fake()->randomElement(['clear', 'soft', 'unsure']),
                'categories' => ['upper_body', 'dresses'],
                'size' => fake()->randomElement(['XS', 'S', 'M', 'L', 'XL', 'XXL']),
                'budget' => fake()->randomElement(['under_200', '200_500', '500_1000', 'over_1000']),
            ],
            'completed_at' => now(),
        ]);
    }

    /** The member chose "Пропустити" on the whole quiz. */
    public function skipped(): static
    {
        return $this->state(fn (): array => [
            'answers' => null,
            'skipped_at' => now(),
        ]);
    }
}
