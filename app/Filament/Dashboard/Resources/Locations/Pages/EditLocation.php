<?php

namespace App\Filament\Dashboard\Resources\Locations\Pages;

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Filament\Dashboard\Resources\Locations\LocationResource;
use App\Filament\Dashboard\Resources\Locations\Tables\LocationsTable;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditLocation extends EditRecord
{
    protected static string $resource = LocationResource::class;

    /**
     * The source of a location is never taken from the form: it can only be kept, or be Traccar
     * for a stationary place made mobile. Nothing becomes a phone location from here.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (($data['type'] ?? null) === LocationType::Mobile->value) {
            $data['mobile_source'] = ($this->record->mobile_source ?? MobileSource::Traccar)->value;
        }

        return $data;
    }

    /**
     * A phone location can become another kind of saved location only while the saved-location
     * limit has room, otherwise the phone's slot would be freed for unlimited places.
     */
    protected function beforeSave(): void
    {
        $becomesStationary = $this->record->isPhoneApp()
            && ($this->data['type'] ?? null) !== LocationType::Mobile->value;

        if (! $becomesStationary || auth()->user()->canCreateLocation()) {
            return;
        }

        Notification::make()
            ->title('Saved-location limit reached')
            ->body('This phone location cannot become a saved place while your plan\'s saved locations are full. Remove a saved place or upgrade first.')
            ->danger()
            ->send();

        $this->halt();
    }

    protected function getHeaderActions(): array
    {
        return [
            LocationsTable::guardedDeleteAction(),
        ];
    }
}
