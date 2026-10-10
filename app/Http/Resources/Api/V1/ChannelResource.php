<?php

namespace App\Http\Resources\Api\V1;

use App\Models\NotificationChannel;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A channel as the app's list and screen need it. Never carries `apprise_url`, `config`, a URL, a
 * token or a confirmation code: `destination` is the type's `displaySummary()` (masked for secret-bearing
 * types such as Discord, Slack, Telegram bot tokens and Pushover; the full address for Email), never a URL
 * or token.
 *
 * @property NotificationChannel $resource
 */
class ChannelResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $channel = $this->resource;
        $type = app(ChannelTypeRegistry::class)->find($channel->channel_type);
        $summary = $type?->displaySummary($channel);
        $hasSummary = $summary !== null && $summary !== '—';

        return [
            'id' => $channel->id,
            'label' => $channel->label,
            'channel_type' => $channel->channel_type,
            'type_label' => $type?->label() ?? $channel->channel_type,
            'destination' => $hasSummary ? $summary : null,
            'destination_label' => $hasSummary ? $type->destinationLabel() : null,
            'verified' => $channel->isVerified(),
            'managed_by_app' => $channel->channel_type === 'mobile_push',
            'created_at' => $channel->created_at?->toIso8601String(),
        ];
    }
}
