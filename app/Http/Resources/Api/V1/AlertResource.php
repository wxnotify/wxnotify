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
 * One feed entry. `channels` covers the group's feed rows only (the feed is the phone's): successful pushes, and
 * held ones marked `in_app`, whose status stays `suppressed`. None is a failure, so `has_failure` is always false;
 * old app builds still read both keys. Never carries error_detail, a destination or a URL secret.
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
        $pushRows = $this->resource->pushRows();
        $category = AlertCategory::tryFrom((string) $first->category);

        return [
            'id' => $this->resource->id(),
            'subject' => $first->subject
                ?? $first->alertRule?->displayName(Units::for($request->user())->system)
                ?? 'Alert',
            'category' => $first->category,
            'category_label' => $category?->label() ?? $first->category,
            'dispatched_at' => $this->resource->latestAt()->toIso8601String(),
            'read' => ! $this->resource->isUnread(),
            'channels' => $pushRows->map(fn (DeliveryLog $row) => $this->channel($row))->values()->all(),
            // Kept for app builds from before read/unread alerts; a feed row is never a failure.
            'has_failure' => false,
            'product_url' => $first->ingestedProduct?->viewUrl(),
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
