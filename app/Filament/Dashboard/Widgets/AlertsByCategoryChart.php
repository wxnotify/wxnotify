<?php

namespace App\Filament\Dashboard\Widgets;

use App\Actions\Dashboard\GetAlertDeliveryStats;
use App\Filament\Dashboard\Widgets\Concerns\HasDeliveryChartRange;
use Filament\Widgets\ChartWidget;

/**
 * Successful deliveries by alert category over the chosen range. A category
 * keeps one fixed color across loads and filters; deliveries with no recorded
 * (or a no-longer-existing) category are grouped as "Other".
 */
class AlertsByCategoryChart extends ChartWidget
{
    use HasDeliveryChartRange;

    /**
     * @var array<string, string>
     */
    public const COLORS = [
        'weather_alerts' => '#dc2626',
        'spc_wpc' => '#f97316',
        'outlook_report' => '#14b8a6',
        'daily_summary' => '#3b82f6',
        'threshold' => '#8b5cf6',
        'lightning' => '#eab308',
        'other' => '#9ca3af',
    ];

    protected static ?int $sort = -2;

    protected int|string|array $columnSpan = 1;

    protected ?string $heading = 'Alerts delivered by category';

    protected ?string $pollingInterval = null;

    protected ?string $maxHeight = '260px';

    public ?string $filter = '30';

    protected function getType(): string
    {
        return 'pie';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getData(): array
    {
        $slices = app(GetAlertDeliveryStats::class)->byCategory(auth()->user(), $this->rangeDays());

        return [
            'datasets' => [[
                'label' => 'Alerts delivered',
                'data' => array_column($slices, 'count'),
                'backgroundColor' => array_map(fn (array $slice): string => self::COLORS[$slice['category'] ?? 'other'], $slices),
            ]],
            'labels' => array_column($slices, 'label'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getOptions(): array
    {
        return [
            'scales' => ['x' => ['display' => false], 'y' => ['display' => false]],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }

    public function getDescription(): ?string
    {
        return ($this->getCachedData()['datasets'][0]['data'] ?? []) === [] ? $this->emptyPeriodMessage() : null;
    }
}
