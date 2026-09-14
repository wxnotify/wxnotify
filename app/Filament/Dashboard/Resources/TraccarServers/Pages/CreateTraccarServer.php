<?php

namespace App\Filament\Dashboard\Resources\TraccarServers\Pages;

use App\Filament\Dashboard\Resources\TraccarServers\TraccarServerResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateTraccarServer extends CreateRecord
{
    protected static string $resource = TraccarServerResource::class;

    protected function beforeCreate(): void
    {
        if (auth()->user()->canCreateTraccarServer()) {
            return;
        }

        Notification::make()
            ->title('Traccar server limit reached')
            ->body('Your plan allows '.auth()->user()->traccarServerLimit().' Traccar server(s). Upgrade to add more.')
            ->danger()
            ->send();

        $this->halt();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }
}
