<?php

namespace Database\Factories;

use App\Models\TraccarServer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TraccarServer>
 */
class TraccarServerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'label' => fake()->company().' Traccar',
            'base_url' => 'https://'.fake()->domainName(),
            'api_token' => fake()->uuid(),
        ];
    }
}
