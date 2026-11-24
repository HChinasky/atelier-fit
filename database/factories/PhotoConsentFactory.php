<?php

namespace Database\Factories;

use App\Models\PhotoConsent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PhotoConsent>
 */
class PhotoConsentFactory extends Factory
{
    protected $model = PhotoConsent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'consent_version' => (string) config('consent.version'),
            'terms_version' => (string) config('terms.version'),
            'accepted_at' => now(),
            'terms_accepted_at' => now(),
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }

    /** A consent given against a wording that has since been replaced. */
    public function outdated(): static
    {
        return $this->state(fn () => ['consent_version' => 'legacy-0']);
    }

    /** A consent old enough to be past the retention period. */
    public function expired(): static
    {
        $age = now()->subYears((int) config('privacy.retention.consent_years', 3))->subDay();

        return $this->state(fn () => [
            'accepted_at' => $age,
            'created_at' => $age,
            'updated_at' => $age,
        ]);
    }
}
