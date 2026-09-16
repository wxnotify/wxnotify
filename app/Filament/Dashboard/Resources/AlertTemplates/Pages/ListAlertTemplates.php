<?php

namespace App\Filament\Dashboard\Resources\AlertTemplates\Pages;

use App\Filament\Dashboard\Resources\AlertTemplates\AlertTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAlertTemplates extends ListRecords
{
    protected static string $resource = AlertTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
