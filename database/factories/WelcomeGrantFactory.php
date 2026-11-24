<?php

namespace Database\Factories;

use App\Models\WelcomeGrant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WelcomeGrant>
 */
class WelcomeGrantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // Shape matches CreditService::identityHash (HMAC-SHA256, hex = 64 chars).
        return [
            'identity_hash' => hash('sha256', fake()->unique()->safeEmail()),
        ];
    }
}
