<?php

namespace App\Actions\Map;

use App\Actions\Traccar\GetTraccarDeviceMap;
use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\Location;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What the app's Map tab needs in one response: the phone (the Location this app token keeps
 * current), the user's places with a position, the centre to open on, the active warnings near them
 * and the layer configuration. Only the caller's own locations and public warning data leave here,
 * and no coordinates come in.
 */
class GetMapSnapshot
{
    public function __construct(private GetMapWarnings $warnings) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $user, ?int $tokenId): array
    {
        /** @var Collection<int, Location> $placed */
        $placed = $user->locations()->orderBy('label')->orderBy('id')->get()
            ->filter(fn (Location $location): bool => $location->hasFix())
            ->values();

        $phone = $tokenId === null ? null : $placed->first(
            fn (Location $location): bool => $location->mobile_source === MobileSource::App && $location->app_token_id === $tokenId,
        );

        $center = $phone ?? $placed->first();

        return [
            'center' => [
                'lat' => $center === null ? null : (float) $center->lat,
                'lng' => $center === null ? null : (float) $center->lng,
                'source' => $center === null ? null : ($phone !== null ? 'phone' : 'place'),
                'label' => $center?->label,
            ],
            'phone' => $phone === null ? null : [
                'lat' => (float) $phone->lat,
                'lng' => (float) $phone->lng,
                'updated_at' => $phone->mobile_position_updated_at?->toIso8601String(),
                'stale' => $this->isStale($phone),
            ],
            'places' => $placed->map(fn (Location $location): array => [
                'id' => $location->id,
                'label' => (string) $location->label,
                'type' => $location->type->value,
                'lat' => (float) $location->lat,
                'lng' => (float) $location->lng,
                'stale' => $this->isStale($location),
            ])->all(),
            'warnings' => $this->warnings->__invoke($this->anchors($phone, $placed)),
            'layers' => [
                'basemaps' => config('wxnotify.map.basemaps'),
                'radar' => config('wxnotify.map.radar'),
                'attribution' => config('wxnotify.map.attribution'),
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * The phone first, then the other places in label order: GetMapWarnings keeps only the first
     * anchors it is given, so the phone's surroundings must never be the ones dropped.
     *
     * @param  Collection<int, Location>  $placed
     * @return list<array{lat: float, lng: float}>
     */
    private function anchors(?Location $phone, Collection $placed): array
    {
        return $placed
            ->sortBy(fn (Location $location): int => $location->is($phone) ? 0 : 1)
            ->map(fn (Location $location): array => ['lat' => (float) $location->lat, 'lng' => (float) $location->lng])
            ->values()
            ->all();
    }

    private function isStale(Location $location): bool
    {
        if ($location->type !== LocationType::Mobile) {
            return false;
        }

        $updatedAt = $location->mobile_position_updated_at;

        return $updatedAt === null || $updatedAt->lt(now()->subMinutes(GetTraccarDeviceMap::STALE_AFTER_MINUTES));
    }
}
