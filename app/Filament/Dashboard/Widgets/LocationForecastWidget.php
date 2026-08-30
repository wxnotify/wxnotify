<?php

namespace App\Filament\Dashboard\Widgets;

use App\Actions\Weather\GetSevenDayForecast;
use App\Models\Location;
use Filament\Widgets\Widget;

class LocationForecastWidget extends Widget
{
    protected string $view = 'filament.dashboard.widgets.location-forecast-widget';

    public ?Location $record = null;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPeriods(): array
    {
        if (! $this->record) {
            return [];
        }

        return app(GetSevenDayForecast::class)($this->record);
    }
}
