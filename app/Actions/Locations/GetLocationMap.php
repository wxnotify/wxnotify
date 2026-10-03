<?php

namespace App\Actions\Locations;

use App\Actions\Traccar\GetTraccarDeviceMap;
use App\Enums\LocationType;
use App\Filament\Dashboard\Resources\Locations\LocationResource;
use App\Models\Location;

/**
 * The data behind the Locations page's map: one entry per location with a
 * known position, from the database only (mobile positions are already stored
 * by the Traccar poller, so no external call is made). A mobile location with
 * no fix yet is left off the map and counted in `unplaced`. A mobile location
 * is stale when its last position update is missing or older than the same
 * threshold the Traccar devices map uses; a stationary one is never stale.
 *
 * Only the fields the map needs leave this class -- no owner id, Traccar
 * server/device identifiers or timezone.
 */
class GetLocationMap
{
    /**
     * @param  iterable<Location>  $locations
     * @return array{locations: list<array{key: string, label: string, type: string, address: ?string, lat: float, lng: float, stale: bool, updated_at: ?string, edit_url: string}>, unplaced: int}
     */
    public function __invoke(iterable $locations): array
    {
        $placed = [];
        $unplaced = 0;

        $ordered = collect($locations)->sort(
            fn (Location $a, Location $b): int => [$a->label, $a->id] <=> [$b->label, $b->id],
        );

        foreach ($ordered as $location) {
            $isMobile = $location->type === LocationType::Mobile;

            if (! $location->hasFix()) {
                if ($isMobile) {
                    $unplaced++;
                }

                continue;
            }

            $updatedAt = $isMobile ? $location->mobile_position_updated_at : null;

            $placed[] = [
                'key' => (string) $location->id,
                'label' => (string) $location->label,
                'type' => $location->type->value,
                'address' => $isMobile ? null : $location->address,
                'lat' => (float) $location->lat,
                'lng' => (float) $location->lng,
                'stale' => $isMobile && ($updatedAt === null || $updatedAt->lt(now()->subMinutes(GetTraccarDeviceMap::STALE_AFTER_MINUTES))),
                'updated_at' => $updatedAt?->toIso8601String(),
                'edit_url' => LocationResource::getUrl('edit', ['record' => $location]),
            ];
        }

        return ['locations' => $placed, 'unplaced' => $unplaced];
    }
}
