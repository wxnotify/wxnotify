<?php

namespace App\Jobs;

use App\Actions\Locations\ReverseGeocodeCoordinates;
use App\Enums\Integration;
use App\Models\Location;
use App\Support\Integrations;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Resolves a mobile location's current position to its nearest street address,
 * off the polling path so a slow or failing geocoder never slows polling down.
 *
 * Reads the location's position when it runs (it may have moved again while
 * queued), skips itself when the lookup is no longer needed, and retries while the
 * geocoder is throttled or unavailable for up to RETRY_MINUTES. At most one is
 * queued per location.
 */
class ReverseGeocodeLocation implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const RETRY_MINUTES = 30;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $locationId) {}

    public function uniqueId(): string
    {
        return (string) $this->locationId;
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(self::RETRY_MINUTES);
    }

    public function handle(ReverseGeocodeCoordinates $geocode): void
    {
        $location = Location::find($this->locationId);

        if ($location === null || ! Integrations::enabled(Integration::ReverseGeocoding) || ! $location->needsAddressLookup()) {
            return;
        }

        $lat = (float) $location->lat;
        $lng = (float) $location->lng;

        $result = $geocode($lat, $lng);

        if ($result['status'] === 'throttled') {
            $this->release(2);

            return;
        }

        if ($result['status'] === 'failed') {
            $this->release(60);

            return;
        }

        // Bookkeeping written by the system, not a subscriber edit: keep it out of the activity log.
        $location->disableLogging();

        try {
            $location->forceFill([
                'address' => $result['address'],
                'address_geocoded_lat' => $lat,
                'address_geocoded_lng' => $lng,
            ])->save();
        } finally {
            $location->enableLogging();
        }
    }
}
