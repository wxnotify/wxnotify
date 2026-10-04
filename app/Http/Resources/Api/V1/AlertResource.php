<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\AlertCategory;
use App\Models\DeliveryLog;
use App\Support\AppAlert;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\Units;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One feed entry. Never carries error_detail, a destination or a URL secret.
 *
 * @property AppAlert $resource
 */
class AlertResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $first = $this->resource->first();
        $category = AlertCategory::tryFrom((string) $first->category);

        return [
            'id' => $this->resource->id(),
            'subject' => $first->subject
                ?? $first->alertRule?->displayName(Units::for($request->user())->system)
                ?? 'Alert',
            'category' => $first->category,
            'category_label' => $category?->label() ?? $first->category,
            'dispatched_at' => $this->resource->latestAt()->toIso8601String(),
            'channels' => $this->resource->rows->map(fn (DeliveryLog $row) => $this->channel($row))->values()->all(),
            'has_failure' => $this->resource->hasFailure(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function channel(DeliveryLog $row): array
    {
        return [
            'type' => $row->channel_type,
            'type_label' => app(ChannelTypeRegistry::class)->find($row->channel_type)?->label() ?? $row->channel_type,
            'status' => $row->status,
        ];
    }
}
