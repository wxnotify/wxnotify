<?php

namespace Database\Factories;

use App\Enums\ProductFamily;
use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => strtoupper(fake()->unique()->lexify('??.?')),
            'name' => fake()->words(3, true),
            'family' => ProductFamily::Warning,
            'has_polygon' => false,
            'status' => ProductStatus::Active,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    public function retired(): static
    {
        return $this->state(fn () => ['status' => ProductStatus::Retired]);
    }
}
