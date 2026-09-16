<?php

namespace App\Filament\Dashboard\Resources\AlertTemplates\Pages;

use App\Filament\Dashboard\Resources\AlertTemplates\AlertTemplateResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAlertTemplate extends CreateRecord
{
    protected static string $resource = AlertTemplateResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }
}
