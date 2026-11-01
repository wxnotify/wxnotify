<?php

namespace Database\Factories;

use App\Models\ProductStorageScan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductStorageScan>
 */
class ProductStorageScanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'status' => ProductStorageScan::STATUS_COMPLETE,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'error' => null,
            'nwws_bytes' => fake()->numberBetween(0, 5_000_000),
            'emwin_byteblaster_bytes' => fake()->numberBetween(0, 5_000_000),
            'emwin_http_bytes' => fake()->numberBetween(0, 5_000_000),
            'file_count' => fake()->numberBetween(0, 10_000),
            'oldest_file_at' => now()->subDays(30),
            'disks' => null,
        ];
    }

    public function complete(): static
    {
        return $this->state(['status' => ProductStorageScan::STATUS_COMPLETE, 'finished_at' => now()]);
    }

    public function failed(): static
    {
        return $this->state([
            'status' => ProductStorageScan::STATUS_FAILED,
            'finished_at' => now(),
            'error' => 'Scan failed.',
        ]);
    }

    public function running(): static
    {
        return $this->state([
            'status' => ProductStorageScan::STATUS_RUNNING,
            'finished_at' => null,
        ]);
    }
}
