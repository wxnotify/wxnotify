<?php

namespace App\Filament\Dashboard\Widgets;

use App\Actions\Dashboard\GetAlertDeliveryStats;
use App\Filament\Dashboard\Widgets\Concerns\HasDeliveryChartRange;
use Filament\Widgets\ChartWidget;

/**
 * Successful deliveries per day over the chosen range, in the subscriber's
 * own day boundaries (see GetAlertDeliveryStats). Every day in the range is
 * plotted, quiet days as 0.
 */
class AlertsPerDayChart extends ChartWidget
{
    use HasDeliveryChartRange;

    protected static ?int $sort = -3;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Alerts delivered per day';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    public ?string $filter = '30';

    protected function getType(): string
    {
        return 'line';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $stats = app(GetAlertDeliveryStats::class)->perDay(auth()->user(), $this->rangeDays());

        return [
            'datasets' => [[
                'label' => 'Alerts delivered',
                'data' => $stats['counts'],
                'borderColor' => '#dc2626',
                'backgroundColor' => 'rgba(220, 38, 38, 0.1)',
                'fill' => true,
                'tension' => 0.3,
                'pointRadius' => 3,
            ]],
            'labels' => $stats['labels'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
        ];
    }

    public function getDescription(): ?string
    {
        return array_sum($this->getCachedData()['datasets'][0]['data'] ?? []) === 0 ? $this->emptyPeriodMessage() : null;
    }
}
