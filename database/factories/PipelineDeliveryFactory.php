<?php

namespace Database\Factories;

use App\Models\PipelineDelivery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PipelineDelivery>
 */
class PipelineDeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'feed' => 'nwws',
            'wmo_header_key' => 'wfus53 kbou '.fake()->numerify('######').' torbou',
            'file_name' => fake()->lexify('????????').'.txt',
            'outcome' => PipelineDelivery::OUTCOME_INGESTED,
            'received_at' => now(),
        ];
    }

    public function forFeed(string $feed): static
    {
        return $this->state(['feed' => $feed]);
    }

    public function duplicate(): static
    {
        return $this->state(['outcome' => PipelineDelivery::OUTCOME_DUPLICATE]);
    }
}
