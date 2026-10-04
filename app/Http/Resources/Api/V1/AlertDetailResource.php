<?php

namespace App\Http\Resources\Api\V1;

use App\Models\DeliveryLog;
use App\Support\Units;
use Illuminate\Http\Request;

/**
 * An alert's detail: the feed entry plus the rule, each channel's own label, and the public
 * product page when there is one.
 */
class AlertDetailResource extends AlertResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $first = $this->resource->first();

        return [
            ...parent::toArray($request),
            'rule_name' => $first->alertRule?->displayName(Units::for($request->user())->system),
            'product_url' => $first->ingestedProduct?->viewUrl(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function channel(DeliveryLog $row): array
    {
        return [...parent::channel($row), 'label' => $row->notificationChannel?->label];
    }
}
