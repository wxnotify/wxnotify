<?php

namespace App\Filament\Dashboard\Widgets\Concerns;

/**
 * The 7/30/90-day range both alert charts share, driven by ChartWidget's
 * built-in filter dropdown. Anything that is not one of the three offered
 * values (a stale or tampered filter) falls back to 30 days.
 */
trait HasDeliveryChartRange
{
    /**
     * @return array<int, string>
     */
    protected function getFilters(): ?array
    {
        return [
            '7' => 'Last 7 days',
            '30' => 'Last 30 days',
            '90' => 'Last 90 days',
        ];
    }

    protected function rangeDays(): int
    {
        return in_array($this->filter, ['7', '30', '90'], true) ? (int) $this->filter : 30;
    }

    protected function emptyPeriodMessage(): string
    {
        return 'No alerts delivered in this period.';
    }
}
