<?php

namespace App\Actions\Locations;

use App\Enums\Integration;
use App\Jobs\ReverseGeocodeLocation;
use App\Models\Location;
use App\Support\Integrations;
use Throwable;

/**
 * A new position needs a street address when the location was never resolved or has moved far
 * enough since it was. Queued, so a slow geocoder never slows the write, and never allowed to
 * fail it. Shared by the Traccar poller and the app's position endpoint.
 */
class QueueAddressLookup
{
    public function __invoke(Location $location): void
    {
        try {
            if (Integrations::enabled(Integration::ReverseGeocoding) && $location->needsAddressLookup()) {
                ReverseGeocodeLocation::dispatch($location->id);
            }
        } catch (Throwable) {
            // The next position tries again.
        }
    }
}
