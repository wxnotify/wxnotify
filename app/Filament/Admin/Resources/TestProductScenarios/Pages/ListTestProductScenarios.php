<?php

namespace App\Filament\Admin\Resources\TestProductScenarios\Pages;

use App\Filament\Admin\Resources\TestProductScenarios\TestProductScenarioResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTestProductScenarios extends ListRecords
{
    protected static string $resource = TestProductScenarioResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('New scenario'),
        ];
    }
}
