<?php

namespace App\Filament\Dashboard\Resources\Locations\Pages;

use App\Enums\LocationType;
use App\Enums\MobileSource;
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
                ->title('Saved-location limit reached')
                ->body('Your plan allows '.auth()->user()->locationLimit().' saved location(s), not counting phones sharing their location. Upgrade to add more.')
                ->danger()
                ->send();

            $this->halt();

            return;
        }

        $data = $this->form->getState();

        if (($data['type'] ?? null) !== LocationType::Mobile->value) {
            return;
        }

        if (($data['mobile_source'] ?? null) === MobileSource::App->value) {
            Notification::make()
                ->title('This location cannot be a phone location')
                ->body('Phone locations are created by sharing from the WxNotify app.')
                ->danger()
                ->send();

            $this->halt();

            return;
        }

        if (auth()->user()->canAddTraccarDevice((int) ($data['traccar_server_id'] ?? 0))) {
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

        // Phone locations are only ever created by a phone sharing from the app.
        $data['mobile_source'] = ($data['type'] ?? null) === LocationType::Mobile->value ? MobileSource::Traccar->value : null;

        return $data;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
