<?php

namespace App\Filament\Dashboard\Pages\LiveWeather;

use App\Actions\Map\GetActiveMesoscaleDiscussions;
use App\Actions\Map\GetLiveWarnings;
use App\Actions\Weather\FetchSpcOutlooks;
use App\Actions\Weather\GetRadarFrames;
use App\Filament\Dashboard\Pages\LiveWeather\Concerns\LimitsDataCalls;
use App\Filament\Dashboard\Resources\Locations\LocationResource;
use App\Models\Location;
use App\Support\LiveWarningKinds;
use App\Support\NexradSites;
use App\Support\SpcOutlookColors;
use App\Support\Timezones;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Renderless;
use UnitEnum;

/**
 * The Live Weather map: radar, current warnings and the SPC layers (watches, convective outlook,
 * Mesoscale Discussions) over the base tiles. Public data only; every signed-in tier may open it. The only per-viewer inputs are
 * the opening view and the default radar site, both taken from the subscriber's own first located
 * saved place, and the profile timezone the frame times are shown in (else the app's).
 */
class Map extends Page
{
    use LimitsDataCalls;

    protected const DEFAULT_CENTER = [39.83, -98.58];

    protected const DEFAULT_ZOOM = 4;

    protected const PLACE_ZOOM = 8;

    /**
     * The most saved places the My places layer draws; the rest are left off and the panel says so.
     */
    protected const MAX_PLACE_MARKERS = 100;

    /**
     * The radar layers the picker offers, in order, with their labels.
     */
    protected const RADAR_LAYERS = [
        'composite' => 'Reflectivity (national)',
        'site_reflectivity' => 'Reflectivity (site)',
        'site_velocity' => 'Velocity (site)',
    ];

    /**
     * The convective outlook days the map offers (the ones with a categorical layer).
     */
    protected const OUTLOOK_DAYS = [1, 2, 3];

    protected const OUTLOOK_CACHE_SECONDS = 120;

    /**
     * The most calls one viewer may make to liveWarnings(), radarFrames() and nearestRadarSite()
     * together in a minute (the app's own map endpoint allows the same). Normal use is far below it.
     */
    protected const DATA_CALLS_PER_MINUTE = 120;

    protected const UNAVAILABLE_OUTLOOK_CACHE_SECONDS = 60;

    protected const UNAVAILABLE_OUTLOOK = ['available' => false, 'issued_at' => null, 'features' => []];

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
     * The recenter button's part of the page config: it returns to the opening view, and its
     * name says whether that view is the viewer's own saved place or the default whole-US view.
     *
     * @return array{from_place: bool, label: string}
     */
    public function getRecenterConfig(): array
    {
        $fromPlace = $this->firstLocatedPlace() !== null;

        return ['from_place' => $fromPlace, 'label' => $fromPlace ? 'Back to my location' : 'Reset the map view'];
    }

    /**
     * The My places layer's part of the page config: the viewer's OWN saved places that have a valid
     * position (never another subscriber's), at most MAX_PLACE_MARKERS of them in id order, each with
     * only its name, its address and its type. Rows with no or impossible coordinates are skipped.
     * Nothing else about a location (phone, channels, device) is sent.
     *
     * @return array{places: list<array{name: string, detail: string, lat: float, lng: float}>, total: int, truncated: bool, locations_url: string}
     */
    public function getPlacesConfig(): array
    {
        return once(function (): array {
            $located = (auth()->user()?->locations() ?? Location::query()->whereRaw('1 = 0'))
                ->whereNotNull('lat')
                ->whereNotNull('lng')
                ->orderBy('id')
                ->get(['id', 'user_id', 'label', 'type', 'address', 'lat', 'lng'])
                ->filter(fn (Location $location): bool => abs((float) $location->lat) <= 90 && abs((float) $location->lng) <= 180)
                ->values();

            $places = $located->take(self::MAX_PLACE_MARKERS)->map(fn (Location $location): array => [
                'name' => trim((string) $location->label) !== '' ? (string) $location->label : 'Saved location',
                'detail' => trim(implode(' · ', array_filter([
                    trim((string) $location->address),
                    ucfirst($location->type->value ?? ''),
                ]))),
                'lat' => (float) $location->lat,
                'lng' => (float) $location->lng,
            ])->all();

            return [
                'places' => $places,
                'total' => $located->count(),
                'truncated' => $located->count() > self::MAX_PLACE_MARKERS,
                'locations_url' => LocationResource::getUrl('index'),
            ];
        });
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
        if ($this->dataCallLimited()) {
            return ['layer' => $layer, 'status' => 'unavailable', 'wms_url' => null, 'wms_layer' => null, 'times' => []];
        }

        return app(GetRadarFrames::class)($layer, $site);
    }

    #[Renderless]
    public function nearestRadarSite(float $lat, float $lng): ?string
    {
        if ($this->dataCallLimited()) {
            return null;
        }

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
     * The warnings part of the page config: the kind the list shows, its event names (one filter
     * chip each) and the timezone the ends are shown in. Nothing here reads the warnings.
     *
     * @return array{kind: string, events: list<string>, timezone: string}
     */
    public function getWarningsConfig(): array
    {
        return [
            'kind' => LiveWarningKinds::SHORT_FUSE,
            'events' => LiveWarningKinds::eventNames(LiveWarningKinds::SHORT_FUSE),
            'timezone' => Timezones::forUserOrDefault(auth()->user()),
        ];
    }

    /**
     * The current events of a kind within the viewer's box. Both come from the browser: the kind
     * must be one of LiveWarningKinds and the box exactly four numeric edges, else nothing is read
     * and the answer is "invalid"; GetLiveWarnings then clamps the box to a bounded one.
     *
     * @param  array<mixed>  $bounds
     * @return array{status: 'ok'|'invalid', events: list<array<string, mixed>>, truncated: bool}
     */
    #[Renderless]
    public function liveWarnings(array $bounds, string $kind): array
    {
        if ($this->dataCallLimited()) {
            return ['status' => 'invalid', 'events' => [], 'truncated' => false];
        }

        $validator = Validator::make(['bounds' => $bounds, 'kind' => $kind], [
            'kind' => ['required', 'string', Rule::in(LiveWarningKinds::all())],
            'bounds' => ['required', 'array:south,west,north,east', 'size:4'],
            'bounds.*' => ['required', 'numeric'],
        ]);

        if ($validator->fails()) {
            return ['status' => 'invalid', 'events' => [], 'truncated' => false];
        }

        $result = app(GetLiveWarnings::class)(array_map(floatval(...), $bounds), $kind);

        return ['status' => 'ok', ...$result];
    }

    /**
     * The SPC layers part of the page config: the kind the watches layer asks liveWarnings() for,
     * the outlook days and the timezone discussion ends are shown in. Nothing here reads SPC data.
     *
     * @return array{watches_kind: string, outlook_days: list<int>, timezone: string}
     */
    public function getLayersConfig(): array
    {
        return [
            'watches_kind' => LiveWarningKinds::WATCHES,
            'outlook_days' => self::OUTLOOK_DAYS,
            'timezone' => Timezones::forUserOrDefault(auth()->user()),
        ];
    }

    /**
     * The categorical convective outlook of a day, each area with its fill colour. The day comes
     * from the browser: anything but a whole number in OUTLOOK_DAYS is unavailable without asking
     * SPC. A day, or its categorical file, that SPC could not give is unavailable too, never a
     * quiet day; an available day with no areas is one. Each day's answer is kept for a while
     * (a shorter while when unavailable), so open maps do not refetch every SPC file while one of
     * them is failing (FetchSpcOutlooks caches only a complete result).
     *
     * @return array{
     *     available: bool,
     *     issued_at: ?string,
     *     features: list<array{label: string, color: string, geometry: array<string, mixed>}>,
     * }
     */
    #[Renderless]
    public function spcOutlook(mixed $day): array
    {
        $validator = Validator::make(['day' => $day], [
            'day' => ['required', 'integer', Rule::in(self::OUTLOOK_DAYS)],
        ]);

        if ($validator->fails()) {
            return self::UNAVAILABLE_OUTLOOK;
        }

        $key = 'live-map-spc-outlook-day-'.(int) $day;
        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $outlook = $this->outlookDay((int) $day);
        Cache::put($key, $outlook, $outlook['available'] ? self::OUTLOOK_CACHE_SECONDS : self::UNAVAILABLE_OUTLOOK_CACHE_SECONDS);

        return $outlook;
    }

    /**
     * The Mesoscale Discussions in effect (public SPC products; nothing per viewer).
     *
     * @return array{status: 'ok', discussions: list<array<string, mixed>>}
     */
    #[Renderless]
    public function mesoscaleDiscussions(): array
    {
        return ['status' => 'ok', 'discussions' => app(GetActiveMesoscaleDiscussions::class)()];
    }

    /**
     * @return array{available: bool, issued_at: ?string, features: list<array{label: string, color: string, geometry: array<string, mixed>}>}
     */
    protected function outlookDay(int $day): array
    {
        $outlook = app(FetchSpcOutlooks::class)()["day_{$day}"] ?? null;
        $areas = $outlook['layers']['cat'] ?? null;

        if (! is_array($areas)) {
            return self::UNAVAILABLE_OUTLOOK;
        }

        return [
            'available' => true,
            'issued_at' => $outlook['issued_at'] ?? null,
            'features' => array_map(fn (array $area): array => [
                'label' => $area['label'],
                'color' => SpcOutlookColors::for($area['label']),
                'geometry' => $area['geometry'],
            ], $areas),
        ];
    }

    /**
     * Counts one data call against the viewer's minute and says whether it is over the limit. A
     * limited call must do no work; the page shows the same notice as for any unavailable answer
     * and recovers on the next call that gets through.
     */
    protected function dataCallLimited(): bool
    {
        return $this->callLimited('live-map-data', self::DATA_CALLS_PER_MINUTE);
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
