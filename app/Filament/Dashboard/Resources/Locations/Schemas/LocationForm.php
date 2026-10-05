<?php

namespace App\Filament\Dashboard\Resources\Locations\Schemas;

use App\Actions\Traccar\ListTraccarDevices;
use App\Actions\Weather\ResolveTimezoneFromCoordinates;
use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\Location;
use App\Support\Timezones;
use Fahiem\FilamentPinpoint\Pinpoint;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rules\Exists;
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
        $isStationary = fn (Get $get): bool => $get('type') === LocationType::Stationary->value;

        return [
            TextInput::make('label')
                ->label('Name this location')
                ->placeholder("Home, Work, Mom's house…")
                ->required()
                ->maxLength(255),
            Radio::make('type')
                ->label('Location type')
                ->options([
                    LocationType::Stationary->value => 'Stationary',
                    LocationType::Mobile->value => 'Mobile (tracked live)',
                ])
                ->default(LocationType::Stationary)
                ->required()
                ->live(),
            ...self::stationaryFields(),
            ...self::mobileFields(),
            TimezoneSelect::make('timezone')
                ->label('Timezone')
                ->searchable()
                ->default(fn (): ?string => Timezones::forUser(auth()->user()))
                ->getTimezoneFromBrowser()
                ->required()
                ->visible($isStationary)
                ->helperText('Starts as your profile timezone when you\'ve set one, otherwise your browser\'s detected timezone — change it if this location is somewhere else (e.g. a relative\'s house). Used for this location\'s quiet hours.'),
        ];
    }

    /**
     * @return array<Component>
     */
    private static function stationaryFields(): array
    {
        $visible = fn (Get $get): bool => $get('type') === LocationType::Stationary->value;

        return [
            Pinpoint::make('coordinates')
                ->label('Location')
                ->provider('leaflet')
                ->latField('lat')
                ->lngField('lng')
                ->addressField('address')
                ->dehydrated(false)
                ->visible($visible),
            Hidden::make('lat')
                ->live()
                ->afterStateUpdated(fn (Set $set, Get $get) => self::seedTimezoneFallback($set, $get))
                ->required($visible)
                ->visible($visible),
            Hidden::make('lng')
                ->live()
                ->afterStateUpdated(fn (Set $set, Get $get) => self::seedTimezoneFallback($set, $get))
                ->required($visible)
                ->visible($visible),
            TextInput::make('address')
                ->label('Resolved address')
                ->readOnly()
                ->visible($visible)
                ->helperText('Filled in automatically from the map above — search, drag the marker, or use your current location.'),
        ];
    }

    /**
     * @return array<Component>
     */
    private static function mobileFields(): array
    {
        $visible = fn (Get $get): bool => $get('type') === LocationType::Mobile->value;
        $isApp = fn (Get $get): bool => $get('mobile_source') === MobileSource::App->value;
        $traccarVisible = fn (Get $get): bool => $visible($get) && ! $isApp($get);
        $appVisible = fn (Get $get): bool => $visible($get) && $isApp($get);

        return [
            Hidden::make('mobile_source')
                ->default(MobileSource::Traccar)
                ->visible($visible),
            Select::make('traccar_server_id')
                ->label('Traccar server')
                ->options(fn (): array => auth()->user()->traccarServers()->pluck('label', 'id')->all())
                ->exists('traccar_servers', 'id', fn (Exists $rule): Exists => $rule->where('user_id', auth()->id()))
                ->required($traccarVisible)
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('traccar_device_id', null))
                ->visible($traccarVisible)
                ->helperText(fn (): ?string => auth()->user()->traccarServers()->exists()
                    ? null
                    : 'No Traccar servers yet — add one under Tools → Traccar Servers first.'),
            Callout::make('This location\'s device is missing')
                ->key('traccar_device_missing_notice')
                ->color('warning')
                ->icon('heroicon-o-exclamation-triangle')
                ->description(fn (?Location $record): string => 'The device linked here no longer exists on the Traccar server'
                    .($record?->traccar_device_missing_at !== null ? ' (first noticed '.$record->traccar_device_missing_at->diffForHumans().')' : '')
                    .', so this location is not being updated. Pick its device again below.')
                ->visible(fn (?Location $record, Get $get): bool => $traccarVisible($get)
                    && $record?->hasMissingDevice() === true
                    && (int) $get('traccar_device_id') === (int) $record->traccar_device_id
                    && (int) $get('traccar_server_id') === (int) $record->traccar_server_id)
                ->columnSpanFull(),
            Select::make('traccar_device_id')
                ->label('Device')
                ->options(fn (Get $get): array => self::deviceOptions($get('traccar_server_id')))
                ->required($traccarVisible)
                ->live()
                ->afterStateUpdated(function (Set $set, Get $get): void {
                    $devices = self::deviceOptions($get('traccar_server_id'));
                    $set('traccar_device_name', $devices[$get('traccar_device_id')] ?? null);
                })
                ->visible($traccarVisible)
                ->helperText(fn (Get $get): ?string => $get('traccar_server_id') !== null && self::deviceOptions($get('traccar_server_id')) === []
                    ? 'Couldn\'t reach this server, or it has no devices — check its URL and token under Tools → Traccar Servers.'
                    : null),
            Hidden::make('traccar_device_name')
                ->visible($traccarVisible),
            Placeholder::make('mobile_app_source')
                ->label('Source')
                ->content(fn (?Location $record): string => $record?->appToken !== null
                    ? 'WxNotify app — '.$record->appToken->name
                    : 'WxNotify app — not linked to a phone. Turn on location sharing in the app to link a phone again.')
                ->visible($appVisible),
            Select::make('mobile_update_interval_minutes')
                ->label('Update every')
                ->options([
                    5 => '5 minutes',
                    10 => '10 minutes',
                    15 => '15 minutes',
                    20 => '20 minutes',
                    25 => '25 minutes',
                    30 => '30 minutes',
                ])
                ->default(15)
                ->required($visible)
                ->visible($visible)
                ->helperText(fn (Get $get): string => $isApp($get)
                    ? 'The app re-reads this each time it opens; tighter intervals sharpen how current this location is at the cost of the phone\'s battery.'
                    : 'Tighter intervals sharpen how current this location is, at the cost of more Traccar/device battery use.'),
            Placeholder::make('mobile_last_known_address')
                ->label('Last known address')
                ->content(fn (?Location $record): string => filled($record?->address) ? (string) $record->address : 'Not looked up yet — it appears after the next position update.')
                ->visible(fn (Get $get, ?Location $record): bool => $visible($get) && $record !== null),
            Placeholder::make('mobile_quiet_hours_note')
                ->label('')
                ->content('Mobile locations don\'t have a fixed timezone, so quiet hours on alert rules tied to this location use the timezone on your Profile page. Until you set one there, they won\'t take effect.')
                ->visible($visible),
        ];
    }

    /**
     * @return array<int, string>
     */
    private static function deviceOptions(?int $traccarServerId): array
    {
        if ($traccarServerId === null) {
            return [];
        }

        $server = auth()->user()->traccarServers()->find($traccarServerId);

        if ($server === null) {
            return [];
        }

        $devices = Cache::remember(
            "traccar-devices:{$server->id}",
            now()->addSeconds(30),
            fn (): ?array => app(ListTraccarDevices::class)($server),
        );

        if ($devices === null) {
            return [];
        }

        return collect($devices)->pluck('name', 'id')->all();
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
