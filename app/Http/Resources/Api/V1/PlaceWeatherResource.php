<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One place's weather card as built by FormatPlaceWeather (available, or just id/label/available false).
 *
 * @property array<string, mixed> $resource
 */
class PlaceWeatherResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
