<?php

namespace Database\Factories;

use App\Enums\StorageDimension;
use App\Models\ProductStorageBucket;
use App\Models\ProductStorageScan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductStorageBucket>
 */
class ProductStorageBucketFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'product_storage_scan_id' => ProductStorageScan::factory(),
            'dimension' => StorageDimension::Source,
            'label' => fake()->word(),
            'bytes' => fake()->numberBetween(0, 1_000_000),
            'file_count' => fake()->numberBetween(0, 1_000),
        ];
    }
}
