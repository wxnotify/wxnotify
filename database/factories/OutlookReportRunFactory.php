<?php

namespace Database\Factories;

use App\Models\AlertRule;
use App\Models\OutlookReportRun;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OutlookReportRun>
 */
class OutlookReportRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $unavailable = ['available' => false, 'issued_at' => null, 'category' => null, 'locations' => []];

        return [
            'user_id' => User::factory(),
            'alert_rule_id' => fn (array $attributes) => AlertRule::factory()->outlookReport()->create(['user_id' => $attributes['user_id']])->id,
            'batch_key' => (string) Str::uuid(),
            'payload' => [
                'days' => [
                    1 => [
                        'available' => true,
                        'issued_at' => '2026-11-02T12:00:00+00:00',
                        'category' => 'slight',
                        'hail' => '5%',
                        'wind' => '15%',
                        'tornado' => '2%',
                        'any_severe' => null,
                        'missing' => [],
                        'locations' => [],
                    ],
                    2 => $unavailable,
                    3 => $unavailable,
                ],
                'extended' => [4 => $unavailable, 5 => $unavailable, 6 => $unavailable, 7 => $unavailable, 8 => $unavailable],
                'pwo' => null,
                'something_forecast' => true,
                'day1_missing' => false,
                'report_date' => '2026-11-02',
            ],
            'maps' => [],
        ];
    }

    public function withMaps(): static
    {
        return $this->state(function (array $attributes) {
            $dir = 'outlook-maps/'.$attributes['batch_key'];

            return ['maps' => [
                'day1' => ['path' => $dir.'/day1.png', 'mime' => 'image/png'],
                'day48' => ['path' => $dir.'/day48.gif', 'mime' => 'image/gif'],
            ]];
        });
    }

    public function old(): static
    {
        return $this->state(fn () => ['created_at' => now()->subDays(OutlookReportRun::MAP_DAYS + 1)]);
    }
}
