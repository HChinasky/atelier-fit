<?php

namespace Database\Factories;

use App\Models\ColorProfile;
use App\Models\ColorProfileImage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ColorProfileImage>
 */
class ColorProfileImageFactory extends Factory
{
    /**
     * Default state: the uploaded photo itself, stored under the shared photo
     * prefix so the existing sweep finds it, on the clock the sweep enforces.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'color_profile_id' => ColorProfile::factory(),
            'kind' => ColorProfileImage::KIND_SOURCE,
            'disk' => (string) config('fitting_room.photo.disk'),
            'path' => sprintf(
                '%s/color/%s/%s/source.jpg',
                (string) config('fitting_room.photo.prefix'),
                now()->format('Y-m-d'),
                fake()->uuid(),
            ),
            'expires_at' => now()->addHours((int) config('privacy.photo_hard_delete_hours')),
        ];
    }

    /** One of the engine's rendered views rather than the photo. */
    public function view(string $kind = 'overlay'): static
    {
        return $this->state(fn (array $attributes): array => [
            'kind' => $kind,
            'path' => str_replace('source.jpg', $kind.'.png', (string) $attributes['path']),
        ]);
    }

    /** Past the hard-delete threshold: the object is gone, the row is due to go. */
    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subHour()]);
    }
}
