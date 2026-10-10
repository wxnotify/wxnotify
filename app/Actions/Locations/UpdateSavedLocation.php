<?php

namespace App\Actions\Locations;

use App\Enums\LocationType;
use App\Models\Location;
use App\Models\User;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * What the app may change on a location: a stationary one's name, position, address and timezone;
 * a mobile one's name and update interval. Never the type, the source or any device link.
 */
class UpdateSavedLocation
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(User $user, Location $location, array $data): Location
    {
        $changes = ['label' => $data['label']];

        if ($location->type === LocationType::Mobile) {
            $changes['mobile_update_interval_minutes'] = (int) $data['update_interval_minutes'];
        } else {
            if (filled($data['timezone'] ?? null)) {
                $changes['timezone'] = $data['timezone'];
            }

            if (isset($data['lat'], $data['lng'])) {
                $changes += [
                    'lat' => $data['lat'],
                    'lng' => $data['lng'],
                    'address' => filled($data['address'] ?? null) ? $data['address'] : null,
                ];
            }
        }

        app(CauserResolver::class)->withCauser($user, fn () => $location->update($changes));

        return $location->refresh();
    }
}
