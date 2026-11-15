<?php

namespace App\Filament\Admin\Widgets;

use App\Actions\Dashboard\GetSystemDeliveryStats;
use App\Support\Timezones;
use Filament\Widgets\ChartWidget;

/**
 * Alert deliveries per day across every subscriber, sent against failed and skipped, in the
 * signed-in staff member's day boundaries. A failure spike or a drop to zero is the sign of a
 * broken channel, feed or worker. Every day in the range is plotted, quiet days as 0.
 */
class DeliveriesPerDayChart extends ChartWidget
{
    protected ?string $heading = 'Alert deliveries per day';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    public ?string $filter = '14';

    /**
     * @return array<string, string>
     */
    protected function getFilters(): array
    {
        return [
            '7' => 'Last 7 days',
            '14' => 'Last 14 days',
            '30' => 'Last 30 days',
        ];
    }

    private function rangeDays(): int
    {
        return in_array($this->filter, ['7', '14', '30'], true) ? (int) $this->filter : 14;
    }

    /**
     * @return array{datasets: list<array<string, mixed>>, labels: list<string>}
     */
    protected function getData(): array
    {
        $stats = app(GetSystemDeliveryStats::class)->perDay(Timezones::forUserOrDefault(auth()->user()), $this->rangeDays());

        return [
            'datasets' => [
                ['label' => 'Sent', 'data' => $stats['sent'], 'backgroundColor' => '#16a34a'],
                ['label' => 'Failed', 'data' => $stats['failed'], 'backgroundColor' => '#dc2626'],
                ['label' => 'Skipped', 'data' => $stats['skipped'], 'backgroundColor' => '#9ca3af'],
            ],
            'labels' => $stats['labels'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'bar';
    }
}
