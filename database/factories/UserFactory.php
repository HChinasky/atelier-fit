<?php

namespace Database\Factories;

use App\Models\StyleProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A user exactly as the passwordless sign-in creates them: nothing but a
     * verified e-mail — no name, no password.
     */
    public function emailOnly(): static
    {
        return $this->state(fn () => [
            'name' => null,
            'password' => null,
        ]);
    }

    /**
     * Has been through the style quiz offer (skipped it), so the dashboard
     * opens instead of sending them to /account/welcome — the state of every
     * member who is not a test about the quiz itself.
     */
    /**
     * A member who has set a profile picture (the key only — tests put the
     * object on a faked disk themselves).
     */
    public function withAvatar(): static
    {
        return $this->state(fn () => [
            'avatar_path' => 'avatars/'.fake()->numberBetween(1, 9999).'/'.fake()->uuid().'.jpg',
        ]);
    }

    /** A member who has picked the interface language (config/locales.php). */
    public function speaking(string $locale): static
    {
        return $this->state(fn () => ['locale' => $locale]);
    }

    public function onboarded(): static
    {
        return $this->afterCreating(fn (User $user) => StyleProfile::factory()->skipped()->for($user)->create());
    }
}
