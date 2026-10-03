<?php

namespace App\Actions\Weather;

use App\Models\ActiveWwa;
use App\Models\Location;
use App\Support\Timezones;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Watches/warnings/advisories/statements currently in effect at a location,
 * read from our own active_wwas table (the same live data alert rules match
 * against), independent of any subscriber's alert rules.
 *
 * Coverage mirrors MatchWeatherAlertRules: when an alert has a polygon, the
 * polygon is the authoritative extent (so a polygon that excludes the point
 * excludes the alert even if its zone codes overlap); only a polygon-less
 * alert falls back to UGC zone/county overlap.
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
                'event' => $wwa->product?->name ?? 'Weather alert',
                'headline' => $wwa->headline,
                'expires' => $wwa->expires?->copy()->setTimezone($timezone)->toIso8601String(),
                'family' => $wwa->product?->family?->value,
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
            ->where(fn (Builder $query) => $query->whereNull('expires')->orWhere('expires', '>', now()))
            ->when(! $includeTest, fn (Builder $query) => $query->where('is_test', false))
            ->where(function (Builder $query) use ($zones, $lng, $lat): void {
                $query->whereRaw('(polygon IS NOT NULL AND ST_Contains(polygon, ST_SetSRID(ST_MakePoint(?, ?), 4269)))', [$lng, $lat]);

                if ($zones !== []) {
                    $query->orWhere(function (Builder $query) use ($zones): void {
                        $query->whereNull('polygon')->where(function (Builder $query) use ($zones): void {
                            foreach ($zones as $zone) {
                                $query->orWhereJsonContains('ugc_codes', $zone);
                            }
                        });
                    });
                }
            })
            ->get();

        return self::sorted($alerts);
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
        return self::FAMILY_RANK[$wwa->product?->family?->value] ?? 99;
    }
}
