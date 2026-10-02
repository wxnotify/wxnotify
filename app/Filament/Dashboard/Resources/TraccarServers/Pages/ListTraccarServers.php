<?php

namespace App\Filament\Dashboard\Resources\TraccarServers\Pages;

use App\Filament\Dashboard\Resources\Concerns\DescribesListPage;
use App\Filament\Dashboard\Resources\TraccarServers\TraccarServerResource;
use App\Filament\Dashboard\Resources\TraccarServers\Widgets\TraccarDevicesMapWidget;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTraccarServers extends ListRecords
{
    use DescribesListPage;

    protected static string $resource = TraccarServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->disabled(fn (): bool => ! auth()->user()->canCreateTraccarServer())
                ->tooltip(fn (): ?string => auth()->user()->canCreateTraccarServer()
                    ? null
                    : 'You\'ve reached your plan\'s Traccar server limit — upgrade to add more.'),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            TraccarDevicesMapWidget::class,
        ];
    }

    protected function pageDescription(): string
    {
        return 'Your own Traccar server connections, used to track a mobile location — a vehicle, for example — in real time, so an alert can follow it wherever it goes.';
    }
}
