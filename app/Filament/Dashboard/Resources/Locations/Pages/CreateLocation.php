<?php

namespace App\Filament\Dashboard\Resources\Locations\Pages;

use App\Enums\LocationType;
use App\Filament\Dashboard\Resources\Locations\LocationResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateLocation extends CreateRecord
{
    protected static string $resource = LocationResource::class;

    protected function beforeCreate(): void
    {
        if (! auth()->user()->canCreateLocation()) {
            Notification::make()
                ->title('Location limit reached')
                ->body('Your plan allows '.auth()->user()->locationLimit().' location(s). Upgrade to add more.')
                ->danger()
                ->send();

            $this->halt();

            return;
        }

        $data = $this->form->getState();

        if (($data['type'] ?? null) !== LocationType::Mobile->value) {
            return;
        }

        if (auth()->user()->canAddTraccarDevice((int) $data['traccar_server_id'])) {
            return;
        }

        Notification::make()
            ->title('Device limit reached')
            ->body('Your plan allows '.auth()->user()->traccarDeviceLimitPerServer().' device(s) per Traccar server. Upgrade to add more.')
            ->danger()
            ->send();

        $this->halt();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['user_id'] = auth()->id();

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
