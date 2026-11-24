<?php

namespace Database\Factories;

use App\Models\Generation;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * Default state: a freshly queued generation with a client photo key and no
     * result yet.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'lead_id' => null,
            'product_id' => Product::factory(),
            'client_photo' => 'uploads/'.fake()->date('Y-m-d').'/'.fake()->uuid().'.jpg',
            'status' => 'queued',
            'fashn_prediction_id' => null,
            'result_urls' => null,
            'error' => null,
            // Matches config('services.fashn.num_samples') and the column default:
            // a Generation made directly gets the same modest look count as the API.
            'num_samples' => 2,
            'with_background' => false,
            'expires_at' => now()->addHours(72),
        ];
    }

    public function queued(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'queued',
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'processing',
            'fashn_prediction_id' => fake()->uuid(),
        ]);
    }

    public function done(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'done',
            'fashn_prediction_id' => fake()->uuid(),
            'result_urls' => ['https://cdn.fashn.test/'.fake()->uuid().'.png'],
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'fashn_prediction_id' => fake()->uuid(),
            'error' => 'generation failed',
        ]);
    }

    /**
     * A generation that requests the optional Background Change step (Phase 5).
     */
    /** The member took this look out of their wardrobe. */
    public function hiddenFromWardrobe(): static
    {
        return $this->state(fn (array $attributes) => [
            'wardrobe_hidden_at' => now(),
        ]);
    }

    /**
     * The member took these pieces out of the look in their wardrobe.
     *
     * @param  list<int>  $productIds
     */
    public function withHiddenPieces(array $productIds): static
    {
        return $this->state(fn (array $attributes) => [
            'wardrobe_hidden_product_ids' => $productIds,
        ]);
    }

    public function withBackground(): static
    {
        return $this->state(fn (array $attributes) => [
            'with_background' => true,
        ]);
    }

    /**
     * A generation producing several looks (FASHN num_samples).
     */
    public function looks(int $count): static
    {
        return $this->state(fn (array $attributes) => [
            'num_samples' => $count,
        ]);
    }

    /**
     * An OUTFIT chain over the given ordered product ids (base layer first). The
     * chain yields a single final look, so num_samples is 1.
     *
     * @param  list<int>  $productIds
     */
    public function outfit(array $productIds): static
    {
        return $this->state(fn (array $attributes) => [
            'product_id' => $productIds[0] ?? Product::factory(),
            'product_ids' => $productIds,
            'num_samples' => 1,
            'outfit_step' => 0,
        ]);
    }
}
