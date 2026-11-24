<?php

namespace Database\Factories;

use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditTransaction>
 */
class CreditTransactionFactory extends Factory
{
    /**
     * Default state: a welcome grant of the configured size.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'generation_id' => null,
            'delta' => (int) config('membership.welcome_credits', 4),
            'reason' => CreditTransaction::REASON_WELCOME,
        ];
    }

    /** A spend of one credit against a generation. */
    public function spend(int $amount = 1): static
    {
        return $this->state(fn () => [
            'generation_id' => Generation::factory(),
            'delta' => -abs($amount),
            'reason' => CreditTransaction::REASON_SPEND,
        ]);
    }

    /** A refund compensating a failed generation's spend. */
    public function refund(int $amount = 1): static
    {
        return $this->state(fn () => [
            'generation_id' => Generation::factory(),
            'delta' => abs($amount),
            'reason' => CreditTransaction::REASON_REFUND,
        ]);
    }
}
