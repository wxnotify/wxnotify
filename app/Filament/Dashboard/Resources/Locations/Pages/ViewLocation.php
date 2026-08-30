<?php

namespace App\Filament\Dashboard\Resources\Locations\Pages;

use App\Filament\Dashboard\Resources\Locations\LocationResource;
use App\Filament\Dashboard\Widgets\LocationForecastWidget;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewLocation extends ViewRecord
{
    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [
            LocationForecastWidget::class,
        ];
    }
}
