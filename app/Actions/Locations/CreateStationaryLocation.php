<?php

namespace App\Actions\Locations;

use App\Actions\Weather\ResolveTimezoneFromCoordinates;
use App\Enums\LocationType;
use App\Exceptions\LocationLimitReached;
use App\Models\Location;
use App\Models\User;
use App\Support\Timezones;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Adds a stationary location for the app. The address is whatever the app resolved (search or
 * reverse lookup); the timezone is the one sent, else the coordinates' own, else the profile's.
 */
class CreateStationaryLocation
{
    /**
     * @param  array{label: string, lat: float|int|string, lng: float|int|string, address?: ?string, timezone?: ?string}  $data
     *
     * @throws LocationLimitReached
     */
    public function __invoke(User $user, array $data): Location
    {
        if (! $user->canCreateLocation()) {
            throw new LocationLimitReached;
        }

        $timezone = filled($data['timezone'] ?? null)
            ? $data['timezone']
            : (app(ResolveTimezoneFromCoordinates::class)((float) $data['lat'], (float) $data['lng'])
                ?? Timezones::forUser($user)
                ?? config('app.timezone'));

        return app(CauserResolver::class)->withCauser($user, fn (): Location => Location::create([
            'user_id' => $user->id,
            'label' => $data['label'],
            'type' => LocationType::Stationary->value,
            'lat' => $data['lat'],
            'lng' => $data['lng'],
            'address' => filled($data['address'] ?? null) ? $data['address'] : null,
            'timezone' => $timezone,
        ]));
    }
}
