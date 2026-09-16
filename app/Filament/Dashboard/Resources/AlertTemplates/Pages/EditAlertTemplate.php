<?php

namespace App\Filament\Dashboard\Resources\AlertTemplates\Pages;

use App\Filament\Dashboard\Resources\AlertTemplates\AlertTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditAlertTemplate extends EditRecord
{
    protected static string $resource = AlertTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
