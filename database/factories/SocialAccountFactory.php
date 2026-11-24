<?php

namespace Database\Factories;

use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SocialAccount>
 */
class SocialAccountFactory extends Factory
{
    protected $model = SocialAccount::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'provider' => SocialAccount::PROVIDER_GOOGLE,
            'provider_id' => (string) fake()->unique()->numerify('#####################'),
            'email' => fake()->unique()->safeEmail(),
        ];
    }

    /** An identity at Apple (opaque subject id, often a relay address). */
    public function apple(): static
    {
        return $this->state(fn () => [
            'provider' => SocialAccount::PROVIDER_APPLE,
            'provider_id' => fake()->unique()->regexify('[0-9]{6}\.[0-9a-f]{32}\.[0-9]{4}'),
            'email' => fake()->unique()->lexify('??????????').'@privaterelay.appleid.com',
        ]);
    }
}
