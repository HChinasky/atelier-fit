<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    /**
     * Default state: a WhatsApp lead tagged try-on-user, not yet pushed to any
     * external funnel (notified_at null — no notifier is configured).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => 'whatsapp',
            'contact' => $this->uaePhoneNumber(),
            'tags' => ['try-on-user'],
            'ip' => fake()->ipv4(),
            'notified_at' => null,
        ];
    }

    /**
     * Lead left by WhatsApp (phone contact).
     */
    public function whatsapp(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => 'whatsapp',
            'contact' => $this->uaePhoneNumber(),
        ]);
    }

    /**
     * Lead left by email.
     */
    public function email(): static
    {
        return $this->state(fn (array $attributes) => [
            'channel' => 'email',
            'contact' => fake()->unique()->safeEmail(),
        ]);
    }

    /**
     * A MEMBER lead: owned by a user, contact = their verified e-mail. This is
     * the shape FittingRoomController mints for a logged-in member.
     */
    public function forUser(User $user): static
    {
        return $this->state(fn (array $attributes) => [
            'user_id' => $user->id,
            'channel' => 'email',
            'contact' => $user->email ?? fake()->unique()->safeEmail(),
            'tags' => ['try-on-user', 'member'],
        ]);
    }

    /**
     * Already pushed to an external funnel (for when a notifier exists).
     */
    public function notified(): static
    {
        return $this->state(fn (array $attributes) => [
            'notified_at' => now(),
        ]);
    }

    /**
     * A plausible UAE mobile number in E.164 form (+9715XXXXXXXX).
     */
    private function uaePhoneNumber(): string
    {
        return '+9715'.fake()->numerify('########');
    }
}
