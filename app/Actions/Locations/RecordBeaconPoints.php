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
 * controller answers with a 404. The location must belong to the token's own account.
 */
class RecordBeaconPoints
{
    public function __construct(private readonly RecordMobilePosition $record) {}

    /**
     * @param  list<array{latitude: float|int|string, longitude: float|int|string, timestamp: string}>  $points
     */
    public function __invoke(PersonalAccessToken $beacon, array $points): ?Location
    {
        $location = Location::query()
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
