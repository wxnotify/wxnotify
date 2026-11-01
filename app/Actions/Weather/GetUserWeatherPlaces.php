<?php

namespace App\Actions\Weather;

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\Location;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * The subscriber's places the Weather card can show: those with a position, the phone (the app
 * location) first, then by id, at most 25.
 */
class GetUserWeatherPlaces
{
    public const MAX_PLACES = 25;

    /**
     * @return Collection<int, Location>
     */
    public function __invoke(User $user): Collection
    {
        return $user->locations()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderByRaw('case when type = ? and mobile_source = ? then 0 else 1 end', [LocationType::Mobile->value, MobileSource::App->value])
            ->orderBy('id')
            ->limit(self::MAX_PLACES)
            ->get();
    }
}
