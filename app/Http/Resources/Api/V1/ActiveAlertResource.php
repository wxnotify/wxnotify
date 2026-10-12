<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One event in effect at the subscriber's places. Public fields only: never zones, polygons or bulletin text.
 *
 * @property array{id: int, event: string, family: string, places: list<string>, expires: ?string, view_url: ?string} $resource
 */
class ActiveAlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource['id'],
            'event' => $this->resource['event'],
            'family' => $this->resource['family'],
            'places' => $this->resource['places'],
            'expires' => $this->resource['expires'],
            'view_url' => $this->resource['view_url'],
        ];
    }
}
