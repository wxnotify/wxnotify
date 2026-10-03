<?php

namespace App\Support;

use App\Models\AlertRule;
use App\Models\Location;
use App\Models\TraccarServer;
use Illuminate\Support\Collection;

/**
 * Which of a subscriber's alerts would silently stop working if a location (or
 * a Traccar server, whose mobile locations are deleted with it) went away.
 *
 * Deleting a location detaches it from every alert's locations list and nulls
 * the single location_id a scheduled alert (Daily Summary, Threshold,
 * Lightning) depends on -- the alert survives but never fires again, with no
 * warning. The subscriber panel's delete actions refuse while this returns
 * anything and name the alerts; staff in the admin panel are deliberately not
 * guarded.
 */
final class LocationDeletionGuard
{
    /**
     * @return Collection<int, AlertRule>
     */
    public static function alertsUsing(Location $location): Collection
    {
        return self::alertsUsingAny([$location->id], $location->user_id);
    }

    /**
     * @return Collection<int, AlertRule>
     */
    public static function alertsUsingServer(TraccarServer $server): Collection
    {
        return self::alertsUsingAny($server->locations()->pluck('id')->all(), $server->user_id);
    }

    /**
     * @param  Collection<int, AlertRule>  $alerts
     */
    public static function summary(Collection $alerts): string
    {
        return AlertNameSummary::of($alerts);
    }

    /**
     * @param  array<int, int>  $locationIds
     * @return Collection<int, AlertRule>
     */
    private static function alertsUsingAny(array $locationIds, int $userId): Collection
    {
        if ($locationIds === []) {
            return new Collection;
        }

        return AlertRule::query()
            ->where('user_id', $userId)
            ->where(fn ($query) => $query
                ->whereIn('location_id', $locationIds)
                ->orWhereHas('locations', fn ($locations) => $locations->whereIn('locations.id', $locationIds)))
            ->with(['products', 'location'])
            ->orderBy('id')
            ->get();
    }
}
