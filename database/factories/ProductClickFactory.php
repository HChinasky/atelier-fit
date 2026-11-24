<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\ProductClick;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductClick>
 */
class ProductClickFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->emailOnly(),
            'product_id' => Product::factory()->verified(),
            'source' => 'color',
            'season' => null,
        ];
    }

    public function fromCatalog(): static
    {
        return $this->state(fn (): array => ['source' => 'catalog']);
    }
}
