<?php

namespace Database\Factories;

use App\Enums\ShapefileVersionStatus;
use App\Models\ShapefileLayer;
use App\Models\ShapefileLayerVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShapefileLayerVersion>
 */
class ShapefileLayerVersionFactory extends Factory
{
    protected $model = ShapefileLayerVersion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shapefile_layer_id' => ShapefileLayer::factory(),
            'filename' => fake()->slug().'.zip',
            'checksum' => fake()->md5(),
            'source_url' => fake()->url(),
            'effective_at' => fake()->date(),
            'status' => ShapefileVersionStatus::Staged,
            'record_count' => fake()->numberBetween(1, 5000),
        ];
    }
}
