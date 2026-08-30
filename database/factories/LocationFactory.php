<?php

namespace Database\Factories;

use App\Enums\LocationType;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->streetName(),
            'type' => LocationType::Stationary,
            'address' => fake()->address(),
            'lat' => fake()->latitude(24, 49),
            'lng' => fake()->longitude(-125, -67),
        ];
    }
}
