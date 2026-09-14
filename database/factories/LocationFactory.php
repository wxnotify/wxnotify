<?php

namespace Database\Factories;

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\Location;
use App\Models\TraccarServer;
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

    public function mobile(): static
    {
        return $this->state(fn () => [
            'type' => LocationType::Mobile,
            'address' => null,
            'lat' => null,
            'lng' => null,
            'mobile_source' => MobileSource::Traccar,
            'mobile_update_interval_minutes' => 15,
            'traccar_server_id' => TraccarServer::factory(),
            'traccar_device_id' => fake()->randomNumber(5),
            'traccar_device_name' => fake()->word(),
        ]);
    }

    public function withFix(float $lat, float $lng): static
    {
        return $this->state(fn () => [
            'lat' => $lat,
            'lng' => $lng,
            'mobile_position_updated_at' => now(),
        ]);
    }
}
