<?php

namespace Database\Factories;

use App\Models\ShapefileLayer;
use App\Models\ShapefileRecordOverride;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShapefileRecordOverride>
 */
class ShapefileRecordOverrideFactory extends Factory
{
    protected $model = ShapefileRecordOverride::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shapefile_layer_id' => ShapefileLayer::factory(),
            'record_key' => fake()->unique()->bothify('??###'),
            'field' => 'name',
            'value' => fake()->words(2, true),
            'created_by' => User::factory(),
        ];
    }
}
