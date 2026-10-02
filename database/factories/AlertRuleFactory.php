<?php

namespace Database\Factories;

use App\Enums\AlertCategory;
use App\Enums\ThresholdDirection;
use App\Enums\ThresholdTimeframe;
use App\Enums\ThresholdVariable;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AlertRule>
 */
class AlertRuleFactory extends Factory
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
            'category' => AlertCategory::Threshold,
            'location_id' => Location::factory(),
            'variable' => ThresholdVariable::Temperature,
            'direction' => ThresholdDirection::Below,
            'threshold_value' => 32,
            'timeframe' => ThresholdTimeframe::Overnight,
            'delivery_time' => '06:00',
        ];
    }

    public function lightning(): static
    {
        return $this->state(fn () => [
            'category' => AlertCategory::Lightning,
            'variable' => null,
            'direction' => null,
            'threshold_value' => null,
            'timeframe' => null,
            'delivery_time' => null,
            'radius_miles' => 15,
        ]);
    }

    public function dailySummary(): static
    {
        return $this->state(fn () => [
            'category' => AlertCategory::DailySummary,
            'variable' => null,
            'direction' => null,
            'threshold_value' => null,
            'timeframe' => null,
            'delivery_time' => '07:00',
        ]);
    }

    /**
     * Attaches the given locations via the alert_rule_location pivot after
     * the rule is created -- mirrors how tests already attach products()/
     * notificationChannels() explicitly, not via definition().
     */
    public function locations(Location ...$locations): static
    {
        return $this->afterCreating(function (AlertRule $rule) use ($locations): void {
            $rule->locations()->attach(collect($locations)->pluck('id'));
        });
    }
}
