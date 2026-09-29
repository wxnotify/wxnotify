<?php

namespace App\Filament\Dashboard\Resources\AlertTemplates\Pages;

use App\Filament\Dashboard\Resources\AlertTemplates\AlertTemplateResource;
use App\Filament\Dashboard\Resources\Concerns\DescribesListPage;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAlertTemplates extends ListRecords
{
    use DescribesListPage;

    protected static string $resource = AlertTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    protected function pageDescription(): string
    {
        return "Reusable message templates that control exactly which details — hazard, source, impact, and more — appear in an alert's body. Attach one to an alert rule instead of using the default format.";
    }
}
