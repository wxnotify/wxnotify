<?php

namespace App\Filament\Dashboard\Resources\TraccarServers\Pages;

use App\Filament\Dashboard\Resources\TraccarServers\TraccarServerResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTraccarServer extends EditRecord
{
    protected static string $resource = TraccarServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
