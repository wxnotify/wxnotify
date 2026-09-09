<?php

namespace App\Filament\Dashboard\Resources\Locations\Schemas;

use App\Actions\Weather\ResolveTimezoneFromCoordinates;
use App\Enums\LocationType;
use Fahiem\FilamentPinpoint\Pinpoint;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
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
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set, Get $get) => self::seedTimezoneFallback($set, $get)),
            Hidden::make('lng')
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set, Get $get) => self::seedTimezoneFallback($set, $get)),
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

    /**
     * Fallback for when the browser-JS auto-detect on the timezone field
     * (TimezoneSelect::getTimezoneFromBrowser()) hasn't run — e.g. JS
     * disabled or blocked — leaving the required field blank. Derives a
     * best-guess timezone from the coordinates the subscriber actually
     * placed on the map instead, via ResolveTimezoneFromCoordinates. Only
     * fires when the field is still blank, so it never overwrites a value
     * the browser or the subscriber already set.
     */
    private static function seedTimezoneFallback(Set $set, Get $get): void
    {
        if (! blank($get('timezone'))) {
            return;
        }

        $lat = $get('lat');
        $lng = $get('lng');

        if (blank($lat) || blank($lng)) {
            return;
        }

        $timezone = app(ResolveTimezoneFromCoordinates::class)((float) $lat, (float) $lng);

        if ($timezone !== null) {
            $set('timezone', $timezone);
        }
    }
}
