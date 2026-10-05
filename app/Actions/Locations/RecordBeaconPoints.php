<?php

namespace App\Actions\Locations;

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\Location;
use Carbon\CarbonImmutable;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Applies a beacon's batch: the newest point moves the location linked to the beacon token's
 * parent app token. Returns null when no location is linked (the phone was unlinked), which the
 * controller answers with a 404. The location must belong to the token's own account. A beacon
 * with no parent app token reaches nothing: it is refused before the query, because a null
 * `where` value would otherwise match an unlinked location (whose `app_token_id` is null).
 */
class RecordBeaconPoints
{
    public function __construct(private readonly RecordMobilePosition $record) {}

    /**
     * @param  list<array{latitude: float|int|string, longitude: float|int|string, timestamp: string}>  $points
     */
    public function __invoke(PersonalAccessToken $beacon, array $points): ?Location
    {
        if ($beacon->parent_token_id === null) {
            return null;
        }

        $location = Location::query()
            ->whereNotNull('app_token_id')
            ->where('app_token_id', $beacon->parent_token_id)
            ->where('user_id', $beacon->tokenable_id)
            ->where('type', LocationType::Mobile->value)
            ->where('mobile_source', MobileSource::App->value)
            ->first();

        if ($location === null) {
            return null;
        }

        $newest = collect($points)
            ->map(fn (array $point): array => [...$point, 'at' => CarbonImmutable::parse($point['timestamp'])])
            ->sortByDesc('at')
            ->first();

        ($this->record)($location, (float) $newest['latitude'], (float) $newest['longitude'], $newest['at']);

        return $location;
    }
}
