<?php

namespace App\Actions\Weather;

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The subscriber's places the Weather card can show: those with a position, the calling device's own
 * location first (when `$appTokenId` is given), then the other phones (the app locations), then by id,
 * at most 25.
 */
class GetUserWeatherPlaces
{
    public const MAX_PLACES = 25;

    /**
     * @return Collection<int, Location>
     */
    public function __invoke(User $user, ?int $appTokenId = null): Collection
    {
        $query = $user->locations()
            ->whereNotNull('lat')
            ->whereNotNull('lng');

        if ($appTokenId !== null) {
            $query->orderByRaw('case when app_token_id = ? then 0 else 1 end', [$appTokenId]);
        }

        return $query
            ->orderByRaw('case when type = ? and mobile_source = ? then 0 else 1 end', [LocationType::Mobile->value, MobileSource::App->value])
            ->orderBy('id')
            ->limit(self::MAX_PLACES)
            ->get();
    }
}
