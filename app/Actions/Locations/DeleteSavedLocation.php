<?php

namespace App\Actions\Locations;

use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Exceptions\LocationInUse;
use App\Exceptions\LocationIsAPhone;
use App\Models\AlertRule;
use App\Models\Location;
use App\Models\User;
use App\Support\LocationDeletionGuard;
use App\Support\Units;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Deletes a location unless a phone keeps it current or an alert still uses it (the dashboard's
 * LocationDeletionGuard: deleting it would silently stop those alerts).
 */
class DeleteSavedLocation
{
    /**
     * @throws LocationIsAPhone
     * @throws LocationInUse
     */
    public function __invoke(User $user, Location $location): void
    {
        if ($location->type === LocationType::Mobile && $location->mobile_source === MobileSource::App && $location->app_token_id !== null) {
            throw new LocationIsAPhone;
        }

        $alerts = LocationDeletionGuard::alertsUsing($location);

        if ($alerts->isNotEmpty()) {
            $units = Units::for($user)->system;

            throw new LocationInUse($alerts->map(fn (AlertRule $alert): string => $alert->displayName($units))->values()->all());
        }

        app(CauserResolver::class)->withCauser($user, fn () => $location->delete());
    }
}
