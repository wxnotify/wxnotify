<?php

namespace Database\Factories;

use App\Models\ActiveWwa;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActiveWwa>
 */
class ActiveWwaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'vtec_office' => 'K'.strtoupper(fake()->lexify('???')),
            'vtec_phenomenon' => strtoupper(fake()->lexify('??')),
            'vtec_significance' => strtoupper(fake()->lexify('?')),
            'vtec_event_number' => str_pad((string) fake()->unique()->numberBetween(1, 9999), 4, '0', STR_PAD_LEFT),
            'vtec_year' => 2026,
            'status' => 'Active',
            'effective' => now(),
            'expires' => now()->addHour(),
            'source' => 'text',
        ];
    }
}
