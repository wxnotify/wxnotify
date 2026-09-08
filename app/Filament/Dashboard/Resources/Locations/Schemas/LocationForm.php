<?php

namespace App\Filament\Dashboard\Resources\Locations\Schemas;

use App\Enums\LocationType;
use Fahiem\FilamentPinpoint\Pinpoint;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Tapp\FilamentTimezoneField\Forms\Components\TimezoneSelect;

class LocationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components(self::fields());
    }

    /**
     * @return array<Component>
     */
    public static function fields(): array
    {
        return [
            TextInput::make('label')
                ->label('Name this location')
                ->placeholder("Home, Work, Mom's house…")
                ->required()
                ->maxLength(255),
            Hidden::make('type')
                ->default(LocationType::Stationary),
            Pinpoint::make('coordinates')
                ->label('Location')
                ->provider('leaflet')
                ->latField('lat')
                ->lngField('lng')
                ->addressField('address')
                ->dehydrated(false),
            Hidden::make('lat')
                ->required(),
            Hidden::make('lng')
                ->required(),
            TextInput::make('address')
                ->label('Resolved address')
                ->readOnly()
                ->helperText('Filled in automatically from the map above — search, drag the marker, or use your current location.'),
            TimezoneSelect::make('timezone')
                ->label('Timezone')
                ->searchable()
                ->getTimezoneFromBrowser()
                ->required()
                ->helperText('Defaults to your browser\'s detected timezone — change it if this location is somewhere else (e.g. a relative\'s house). Used for this location\'s quiet hours.'),
        ];
    }
}
