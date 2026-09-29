<?php

namespace App\Filament\Dashboard\Resources\Locations\Pages;

use App\Filament\Dashboard\Resources\Concerns\DescribesListPage;
use App\Filament\Dashboard\Resources\Locations\LocationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLocations extends ListRecords
{
    use DescribesListPage;

    protected static string $resource = LocationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->disabled(fn (): bool => ! auth()->user()->canCreateLocation())
                ->tooltip(fn (): ?string => auth()->user()->canCreateLocation()
                    ? null
                    : 'You\'ve reached your plan\'s location limit — upgrade to add more.'),
        ];
    }

    protected function pageDescription(): string
    {
        return 'Places you want alerts and forecasts tied to — your home, a cabin, or a vehicle tracked through Traccar. Add a location here before creating a location-based alert.';
    }
}
