<?php

namespace App\Actions\Locations;

use App\Models\Location;
use Carbon\CarbonInterface;

/**
 * Applies one reported fix to a mobile location. A fix older than the stored one is ignored
 * (a late beacon must not move the pin backwards). The write must not create an activity row:
 * the model logs lat/lng so a subscriber's own repositioning is recorded, which a position that
 * changes every few minutes forever would drown, so logging is switched off around this save,
 * exactly as PollTraccarPositions does.
 */
class RecordMobilePosition
{
    public function __construct(private readonly QueueAddressLookup $lookup) {}

    public function __invoke(Location $location, float $lat, float $lng, CarbonInterface $fixedAt): bool
    {
        if ($location->mobile_position_fixed_at !== null && $fixedAt->lt($location->mobile_position_fixed_at)) {
            return false;
        }

        $location->disableLogging();

        try {
            $location->forceFill([
                'lat' => $lat,
                'lng' => $lng,
                'mobile_position_updated_at' => now(),
                'mobile_position_fixed_at' => $fixedAt,
            ])->save();
        } finally {
            $location->enableLogging();
        }

        ($this->lookup)($location);

        return true;
    }
}
