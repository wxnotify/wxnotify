<?php

namespace App\Actions\Weather;

use App\Models\ActiveWwa;
use App\Models\ActiveWwaArea;
use App\Models\Location;
use App\Support\Timezones;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Watches/warnings/advisories/statements currently in effect at a location,
 * read from our own active_wwas table (the same live data alert rules match
 * against), independent of any subscriber's alert rules.
 *
 * Where an event is in effect is its zone rows (active_wwa_areas), not the event row's own
 * ugc_codes/polygon, which only describe the latest bulletin of an event issued as many. An
 * Active, unexpired zone row covers the location when its polygon (if it has one) contains the
 * point -- the polygon is the authoritative extent, so a polygon that excludes the point excludes
 * the alert even if the zone is listed -- or, for a row without a polygon, when its zone is one of
 * the location's zone or county codes. The event is listed once, with the latest end of the rows
 * that cover the location (none if any has none), set on the returned model for this lookup only.
 * An event with no zone rows at all (a row from before they existed) falls back to its own polygon
 * and zones, and its own end.
 */
class GetActiveAlertsForLocation
{
    /**
     * Lower sorts first. Anything not listed (SPC/WPC, informational, etc.)
     * sorts after statements.
     */
    private const FAMILY_RANK = ['warning' => 0, 'watch' => 1, 'advisory' => 2, 'statement' => 3];

    /**
     * @return array<int, array{event: string, headline: ?string, expires: ?string, family: ?string}>
     */
    public function __invoke(Location $location, bool $includeTest = false): array
    {
        if (! $location->hasFix()) {
            return [];
        }

        $timezone = Timezones::forLocation($location) ?? config('app.timezone');

        return $this->activeFor($location, $includeTest)
            ->map(fn (ActiveWwa $wwa): array => [
                'event' => $wwa->eventName(),
                'headline' => $wwa->headline,
                'expires' => $wwa->expires?->copy()->setTimezone($timezone)->toIso8601String(),
                'family' => $wwa->eventFamily(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, ActiveWwa> with product loaded, sorted by family rank, expiry, id
     */
    public function activeFor(Location $location, bool $includeTest = false): Collection
    {
        if (! $location->hasFix()) {
            return new Collection;
        }

        $zones = $this->zonesFor($location);
        $lng = (float) $location->lng;
        $lat = (float) $location->lat;

        $alerts = ActiveWwa::query()
            ->with('product')
            ->where('status', 'Active')
            ->when(! $includeTest, fn (Builder $query) => $query->where('is_test', false))
            ->where(function (Builder $query) use ($zones, $lng, $lat): void {
                $query->whereHas('areas', fn (Builder $areas) => $this->coveringAreas($areas, $zones, $lng, $lat))
                    ->orWhere(fn (Builder $own) => $this->coveredByOwnColumns($own->whereDoesntHave('areas'), $zones, $lng, $lat));
            })
            ->get();

        $this->useCoveringExpiry($alerts, $zones, $lng, $lat);

        return self::sorted($alerts);
    }

    /**
     * Zone rows that are Active, not yet ended, and cover the point (see the class docblock).
     * Columns are qualified because this is also used inside a subquery on active_wwas.
     *
     * @param  Builder<ActiveWwaArea>  $areas
     * @param  array<int, string>  $zones
     * @return Builder<ActiveWwaArea>
     */
    private function coveringAreas(Builder $areas, array $zones, float $lng, float $lat): Builder
    {
        return $areas
            ->where('active_wwa_areas.status', 'Active')
            ->where(fn (Builder $ends) => $ends->whereNull('active_wwa_areas.expires')->orWhere('active_wwa_areas.expires', '>', now()))
            ->where(function (Builder $query) use ($zones, $lng, $lat): void {
                $query->whereRaw('(active_wwa_areas.polygon IS NOT NULL AND ST_Contains(active_wwa_areas.polygon, ST_SetSRID(ST_MakePoint(?, ?), 4269)))', [$lng, $lat]);

                if ($zones !== []) {
                    $query->orWhere(fn (Builder $byZone) => $byZone->whereNull('active_wwa_areas.polygon')->whereIn('active_wwa_areas.ugc_code', $zones));
                }
            });
    }

    /**
     * The pre-zone-row rule, kept for an event that has no zone rows: its own polygon when it has
     * one, else its own zone codes, and its own end.
     *
     * @param  Builder<ActiveWwa>  $query
     * @param  array<int, string>  $zones
     * @return Builder<ActiveWwa>
     */
    private function coveredByOwnColumns(Builder $query, array $zones, float $lng, float $lat): Builder
    {
        return $query
            ->where(fn (Builder $ends) => $ends->whereNull('expires')->orWhere('expires', '>', now()))
            ->where(function (Builder $covers) use ($zones, $lng, $lat): void {
                $covers->whereRaw('(polygon IS NOT NULL AND ST_Contains(polygon, ST_SetSRID(ST_MakePoint(?, ?), 4269)))', [$lng, $lat]);

                if ($zones !== []) {
                    $covers->orWhere(function (Builder $byZone) use ($zones): void {
                        $byZone->whereNull('polygon')->where(function (Builder $codes) use ($zones): void {
                            foreach ($zones as $zone) {
                                $codes->orWhereJsonContains('ugc_codes', $zone);
                            }
                        });
                    });
                }
            });
    }

    /**
     * Replaces each event's end with the latest end of the zone rows that cover the location (none
     * when any covering row has none). The models are never saved; an event read from its own
     * columns (no covering rows) keeps its own end.
     *
     * @param  Collection<int, ActiveWwa>  $alerts
     * @param  array<int, string>  $zones
     */
    private function useCoveringExpiry(Collection $alerts, array $zones, float $lng, float $lat): void
    {
        if ($alerts->isEmpty()) {
            return;
        }

        $covering = $this->coveringAreas(ActiveWwaArea::query(), $zones, $lng, $lat)
            ->whereIn('active_wwa_areas.active_wwa_id', $alerts->modelKeys())
            ->get(['active_wwa_areas.active_wwa_id', 'active_wwa_areas.expires'])
            ->groupBy('active_wwa_id');

        foreach ($alerts as $wwa) {
            $rows = $covering->get($wwa->id);

            if ($rows !== null) {
                $wwa->expires = $rows->contains(fn (ActiveWwaArea $row): bool => $row->expires === null) ? null : $rows->max('expires');
            }
        }
    }

    /**
     * @param  Collection<int, ActiveWwa>  $wwas
     * @return Collection<int, ActiveWwa>
     */
    public static function sorted(Collection $wwas): Collection
    {
        return $wwas->sortBy([
            fn (ActiveWwa $a, ActiveWwa $b): int => self::rankOf($a) <=> self::rankOf($b),
            fn (ActiveWwa $a, ActiveWwa $b): int => ($a->expires?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->expires?->getTimestamp() ?? PHP_INT_MAX),
            fn (ActiveWwa $a, ActiveWwa $b): int => $a->id <=> $b->id,
        ])->values();
    }

    /**
     * @return array<int, string>
     */
    private function zonesFor(Location $location): array
    {
        if ($location->forecast_zone_ugc === null && $location->county_ugc === null) {
            app(ResolveLocationZones::class)($location);
            $location->refresh();
        }

        return array_values(array_filter([$location->forecast_zone_ugc, $location->county_ugc]));
    }

    private static function rankOf(ActiveWwa $wwa): int
    {
        return self::FAMILY_RANK[$wwa->eventFamily()] ?? 99;
    }
}
