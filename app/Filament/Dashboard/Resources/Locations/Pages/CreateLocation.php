<?php

namespace App\Filament\Dashboard\Resources\Locations\Pages;

use App\Filament\Dashboard\Resources\Locations\LocationResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateLocation extends CreateRecord
{
    protected static string $resource = LocationResource::class;

    protected function beforeCreate(): void
    {
        if (auth()->user()->canCreateLocation()) {
            return;
        }

        Notification::make()
            ->title('Location limit reached')
            ->body('Your plan allows '.auth()->user()->locationLimit().' location(s). Upgrade to add more.')
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
