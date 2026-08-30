<?php

namespace Database\Factories;

use App\Enums\ActivityAlertScope;
use App\Enums\ActivityAlertType;
use App\Models\ActivityAlertRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityAlertRule>
 */
class ActivityAlertRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(3, true),
            'scope' => ActivityAlertScope::AllSubscribers,
            'activity_types' => [ActivityAlertType::AccountDeleted->value],
            'apprise_target' => 'slack://token/botname/channel',
            'is_active' => true,
        ];
    }
}
