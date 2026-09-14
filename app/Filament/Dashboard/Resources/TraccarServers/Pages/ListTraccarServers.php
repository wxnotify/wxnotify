<?php

namespace App\Filament\Dashboard\Resources\TraccarServers\Pages;

use App\Filament\Dashboard\Resources\TraccarServers\TraccarServerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTraccarServers extends ListRecords
{
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
}
