<?php

namespace App\Filament\Dashboard\Pages\LiveWeather;

use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The Live Weather map: radar, current warnings and SPC layers (added by later tasks) over the
 * base tiles. Public data only; every signed-in tier may open it. The only per-viewer input is
 * the opening view, taken from the subscriber's own first located saved place.
 */
class Map extends Page
{
    protected const DEFAULT_CENTER = [39.83, -98.58];

    protected const DEFAULT_ZOOM = 4;

    protected const PLACE_ZOOM = 8;

    protected string $view = 'filament.dashboard.pages.live-weather.map';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::Map;

    protected static string|UnitEnum|null $navigationGroup = 'Live Weather';

    protected static ?string $navigationLabel = 'Map';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Live map';

    protected static ?string $slug = 'live-weather/map';

    /**
     * @return array{center: array{0: float, 1: float}, zoom: int}
     */
    public function getOpeningView(): array
    {
        $place = auth()->user()?->locations()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('id')
            ->first();

        if ($place === null) {
            return ['center' => self::DEFAULT_CENTER, 'zoom' => self::DEFAULT_ZOOM];
        }

        return ['center' => [(float) $place->lat, (float) $place->lng], 'zoom' => self::PLACE_ZOOM];
    }
}
