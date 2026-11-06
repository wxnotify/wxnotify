<?php

namespace App\Filament\Dashboard\Pages\LiveWeather;

use App\Actions\Weather\GetRadarFrames;
use App\Models\Location;
use App\Support\NexradSites;
use App\Support\Timezones;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Renderless;
use UnitEnum;

/**
 * The Live Weather map: radar, current warnings and SPC layers (added by later tasks) over the
 * base tiles. Public data only; every signed-in tier may open it. The only per-viewer inputs are
 * the opening view and the default radar site, both taken from the subscriber's own first located
 * saved place, and the profile timezone the frame times are shown in (else the app's).
 */
class Map extends Page
{
    protected const DEFAULT_CENTER = [39.83, -98.58];

    protected const DEFAULT_ZOOM = 4;

    protected const PLACE_ZOOM = 8;

    /**
     * The radar layers the picker offers, in order, with their labels.
     */
    protected const RADAR_LAYERS = [
        'composite' => 'Reflectivity (national)',
        'site_reflectivity' => 'Reflectivity (site)',
        'site_velocity' => 'Velocity (site)',
    ];

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
        $place = $this->firstLocatedPlace();

        if ($place === null) {
            return ['center' => self::DEFAULT_CENTER, 'zoom' => self::DEFAULT_ZOOM];
        }

        return ['center' => [(float) $place->lat, (float) $place->lng], 'zoom' => self::PLACE_ZOOM];
    }

    /**
     * The radar part of the page config. Nothing here asks NOAA: the browser asks for frames later.
     *
     * @return array{layers: list<string>, default_site: ?string, timezone: string}
     */
    public function getRadarConfig(): array
    {
        $place = $this->firstLocatedPlace();

        return [
            'layers' => array_keys(self::RADAR_LAYERS),
            'default_site' => $place === null ? null : NexradSites::nearest((float) $place->lat, (float) $place->lng),
            'timezone' => Timezones::forUserOrDefault(auth()->user()),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function getRadarLayerOptions(): array
    {
        return self::RADAR_LAYERS;
    }

    /**
     * The frame times for a radar layer. The site comes from the browser, so it is checked
     * against the site list (inside GetRadarFrames) before it reaches a URL; a bad value is
     * "unavailable".
     *
     * @return array{layer: string, status: 'ok'|'unavailable', wms_url: ?string, wms_layer: ?string, times: list<string>}
     */
    #[Renderless]
    public function radarFrames(string $layer, ?string $site = null): array
    {
        return app(GetRadarFrames::class)($layer, $site);
    }

    #[Renderless]
    public function nearestRadarSite(float $lat, float $lng): ?string
    {
        if (! is_finite($lat) || ! is_finite($lng) || abs($lat) > 90 || abs($lng) > 180) {
            return null;
        }

        return NexradSites::nearest($lat, $lng);
    }

    /**
     * The radar sites for the site picker, sorted by name.
     *
     * @return list<array{id: string, name: string}>
     */
    public function radarSites(): array
    {
        $sites = [];

        foreach (NexradSites::all() as $id => $site) {
            $sites[] = ['id' => $id, 'name' => $site['name']];
        }

        usort($sites, fn (array $a, array $b): int => strnatcasecmp($a['name'], $b['name']) ?: strcmp($a['id'], $b['id']));

        return $sites;
    }

    /**
     * The subscriber's own first saved place that has coordinates.
     */
    protected function firstLocatedPlace(): ?Location
    {
        return once(fn (): ?Location => auth()->user()?->locations()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('id')
            ->first());
    }
}
