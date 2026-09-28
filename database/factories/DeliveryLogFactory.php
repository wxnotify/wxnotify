<?php

namespace Database\Factories;

use App\Models\DeliveryLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryLog>
 */
class DeliveryLogFactory extends Factory
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
            'channel_type' => 'discord',
            'status' => 'success',
            'error_detail' => null,
            'subject' => fake()->sentence(4),
            'dispatched_at' => now(),
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failure',
            'error_detail' => 'Connection timed out.',
        ]);
    }

    public function skipped(): static
    {
        return $this->state(fn () => [
            'status' => 'skipped',
            'error_detail' => 'The rule was deactivated after this delivery was dispatched.',
        ]);
    }
}
