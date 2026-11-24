<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\MarketingConsent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MarketingConsent>
 */
class MarketingConsentFactory extends Factory
{
    protected $model = MarketingConsent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => Lead::factory(),
            'channel' => 'whatsapp',
            'consent_version' => (string) config('consent.marketing.version'),
            'consented_at' => now(),
            'unsubscribed_at' => null,
            'ip' => fake()->ipv4(),
        ];
    }

    /** Someone who has opted out — kept as proof, never marketed to again. */
    public function unsubscribed(): static
    {
        return $this->state(fn () => ['unsubscribed_at' => now()->subDay()]);
    }

    public function email(): static
    {
        return $this->state(fn () => ['channel' => 'email']);
    }
}
