<?php

namespace Database\Factories;

use App\Models\ColorProfile;
use App\Models\ColorProfileLabel;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ColorProfileLabel>
 */
class ColorProfileLabelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'color_profile_id' => ColorProfile::factory()->classified(),
            'user_id' => User::factory()->state(['is_stylist' => true]),
            'season' => fake()->randomElement(ColorProfileLabel::SEASONS),
            'season_alt' => null,
            'undertone' => fake()->randomElement(ColorProfileLabel::UNDERTONES),
            'depth' => fake()->randomElement(ColorProfileLabel::DEPTHS),
            'chroma' => fake()->randomElement(ColorProfileLabel::CHROMAS),
            'stylist_confidence' => fake()->randomElement(ColorProfileLabel::CONFIDENCES),
            'notes' => null,
        ];
    }

    public function season(string $season, ?string $alt = null): static
    {
        return $this->state(fn () => ['season' => $season, 'season_alt' => $alt]);
    }

    /**
     * Only what the form requires. Everything else a stylist may leave blank,
     * and a report that assumed otherwise would be reporting on a form nobody
     * fills in.
     */
    public function minimal(): static
    {
        return $this->state(fn () => [
            'season_alt' => null,
            'undertone' => null,
            'depth' => null,
            'chroma' => null,
            'notes' => null,
        ]);
    }
}
