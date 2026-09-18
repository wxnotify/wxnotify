<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\TestProductScenario;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestProductScenario>
 */
class TestProductScenarioFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'product_id' => Product::factory(),
            'input' => [
                'mode' => 'new',
                'wfo' => 'TWC',
                'expires_minutes' => 60,
            ],
        ];
    }
}
