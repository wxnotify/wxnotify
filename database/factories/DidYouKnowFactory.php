<?php

namespace Database\Factories;

use App\Models\DidYouKnow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DidYouKnow>
 */
class DidYouKnowFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'body' => fake()->sentence(),
            'is_active' => true,
            'sort_order' => fake()->numberBetween(1, 100),
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }
}
