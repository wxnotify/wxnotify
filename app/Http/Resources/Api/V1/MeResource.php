<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Support\Units;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
class MeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tier = $this->tier();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'tier' => ['value' => $tier->value, 'label' => ucfirst($tier->value)],
            'unit_system' => Units::for($this->resource)->system->value,
            'timezone' => $this->timezone,
        ];
    }
}
