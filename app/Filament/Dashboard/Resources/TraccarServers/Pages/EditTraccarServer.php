<?php

namespace App\Filament\Dashboard\Resources\TraccarServers\Pages;

use App\Filament\Dashboard\Resources\TraccarServers\Tables\TraccarServersTable;
use App\Filament\Dashboard\Resources\TraccarServers\TraccarServerResource;
use Filament\Resources\Pages\EditRecord;

class EditTraccarServer extends EditRecord
{
    protected static string $resource = TraccarServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            TraccarServersTable::guardedDeleteAction(),
        ];
    }
}
