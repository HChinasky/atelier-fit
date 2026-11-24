<?php

namespace Database\Factories;

use App\Models\OtpCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<OtpCode>
 */
class OtpCodeFactory extends Factory
{
    /**
     * The plaintext behind the default code_hash — tests that verify a code
     * use this constant instead of re-hashing their own.
     */
    public const PLAIN_CODE = '123456';

    protected $model = OtpCode::class;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'code_hash' => Hash::make(self::PLAIN_CODE),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => null,
        ];
    }

    /** A code whose TTL has already run out. */
    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    /** A code that was already used once (single-use spent). */
    public function consumed(): static
    {
        return $this->state(fn () => ['consumed_at' => now()->subMinute()]);
    }

    /** A code hashed from the given plaintext instead of the default. */
    public function withCode(string $code): static
    {
        return $this->state(fn () => ['code_hash' => Hash::make($code)]);
    }
}
