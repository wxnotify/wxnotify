<?php

namespace App\Http\Resources\Api\V1;

use App\Actions\Traccar\GetTraccarDeviceMap;
use App\Enums\LocationType;
use App\Enums\MobileSource;
use App\Models\Location;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A location as the app's list and editor need it. Never carries the phone's token id, a Traccar
 * server or device id, or any credential.
 *
 * @property Location $resource
 */
class SavedLocationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $location = $this->resource;
        $mobile = $location->type === LocationType::Mobile;
        $updatedAt = $location->mobile_position_updated_at;

        return [
            'id' => $location->id,
            'label' => $location->label,
            'type' => $location->type->value,
            'source' => $location->mobile_source?->value,
            'source_label' => $location->mobile_source?->label(),
            'address' => $location->address,
            'lat' => $location->lat === null ? null : (float) $location->lat,
            'lng' => $location->lng === null ? null : (float) $location->lng,
            'timezone' => $location->timezone,
            'update_interval_minutes' => $location->mobile_update_interval_minutes,
            'linked' => $location->mobile_source === MobileSource::App ? $location->app_token_id !== null : null,
            'device_name' => $location->mobile_source === MobileSource::Traccar ? $location->traccar_device_name : null,
            'has_missing_device' => $location->hasMissingDevice(),
            'has_fix' => $location->hasFix(),
            'last_fix_at' => $mobile ? $location->mobile_position_fixed_at?->toIso8601String() : null,
            'position_updated_at' => $mobile ? $updatedAt?->toIso8601String() : null,
            'stale' => $mobile ? ($updatedAt === null || $updatedAt->lt(now()->subMinutes(GetTraccarDeviceMap::STALE_AFTER_MINUTES))) : null,
        ];
    }
}
