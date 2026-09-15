<?php

namespace Database\Factories;

use App\Enums\AlertCategory;
use App\Enums\AlertTemplateSection;
use App\Models\AlertTemplate;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertTemplate>
 */
class AlertTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(3, true),
            'category' => AlertCategory::WeatherAlerts,
            'subject_template' => null,
            'sections' => [AlertTemplateSection::Hazard->value, AlertTemplateSection::Source->value, AlertTemplateSection::Impact->value],
        ];
    }

    public function spcWpc(): static
    {
        return $this->state(fn (): array => [
            'category' => AlertCategory::SpcWpc,
            'sections' => null,
        ]);
    }
}
