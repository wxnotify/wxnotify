<?php

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use App\Support\ChannelTypes\ChannelSetupField;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A channel type the app can create. Wraps `['type' => ChannelType, 'user' => User]`; the fields are
 * the descriptors without their validation rules.
 */
class ChannelTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ChannelType $type */
        $type = $this->resource['type'];
        /** @var User $user */
        $user = $this->resource['user'];
        $canAdd = $user->canAddChannel($type->slug());

        return [
            'slug' => $type->slug(),
            'label' => $type->label(),
            'can_add' => $canAdd,
            'unavailable_reason' => $canAdd ? null : 'channel_limit',
            'fields' => array_map(fn (ChannelSetupField $field): array => $field->toArray(), $type->appSetupFields($user) ?? []),
        ];
    }
}
