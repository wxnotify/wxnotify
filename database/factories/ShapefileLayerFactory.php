<?php

namespace Database\Factories;

use App\Models\ShapefileLayer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShapefileLayer>
 */
class ShapefileLayerFactory extends Factory
{
    protected $model = ShapefileLayer::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => fake()->unique()->slug(2, false),
            'name' => fake()->words(2, true),
            'source_url' => fake()->url(),
        ];
    }
}
