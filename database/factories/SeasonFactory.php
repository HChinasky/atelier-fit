<?php

namespace Database\Factories;

use App\Models\Season;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Season>
 */
class SeasonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->randomElement(self::SLUGS),
            'sort' => fake()->numberBetween(0, 11),
        ];
    }

    /**
     * The engine's own 12. Listed rather than generated: a factory that invented
     * a slug would let a test pass against a season the engine cannot return.
     *
     * @var list<string>
     */
    public const SLUGS = [
        'true_spring', 'true_autumn', 'true_winter', 'true_summer',
        'deep_autumn', 'deep_winter', 'light_spring', 'light_summer',
        'bright_spring', 'bright_winter', 'soft_autumn', 'soft_summer',
    ];

    public function slug(string $slug): static
    {
        return $this->state(fn () => ['slug' => $slug]);
    }
}
