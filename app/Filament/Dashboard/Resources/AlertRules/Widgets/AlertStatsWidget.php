<?php

namespace App\Filament\Dashboard\Resources\AlertRules\Widgets;

use App\Actions\Alerts\GetAlertStats;
use App\Filament\Dashboard\Resources\AlertRules\AlertRuleResource;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The stats strip above the My Alerts table. Database only; every number comes
 * from GetAlertStats for the signed-in subscriber. canView() mirrors the
 * resource's gate (which allows everyone today): the protection is that the
 * data is always scoped to auth()->user().
 */
class AlertStatsWidget extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return AlertRuleResource::canAccess();
    }

    /**
     * @return int|array<string, ?int>|null
     */
    protected function getColumns(): int|array|null
    {
        return ['default' => 1, 'sm' => 2, 'xl' => 4];
    }

    /**
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $stats = app(GetAlertStats::class)(auth()->user());

        return [
            Stat::make('Active alerts', "{$stats['active']} of {$stats['total']}")
                ->color('primary'),
            Stat::make('Deliveries, last 7 days', $stats['deliveries'])
                ->description($this->trend($stats['deliveries'], $stats['previous_deliveries']))
                ->chart($stats['daily'])
                ->color('primary'),
            Stat::make('Failed deliveries, last 7 days', $stats['failures'])
                ->color($stats['failures'] > 0 ? 'danger' : 'success'),
            Stat::make('Held for quiet hours, last 7 days', $stats['held'])
                ->description('Not sent because of quiet hours')
                ->color('gray'),
        ];
    }

    private function trend(int $current, int $previous): string
    {
        $difference = $current - $previous;

        return match (true) {
            $difference > 0 => "{$difference} more than the previous 7 days",
            $difference < 0 => abs($difference).' fewer than the previous 7 days',
            default => 'Same as the previous 7 days',
        };
    }
}
