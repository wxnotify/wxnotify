<?php

namespace App\Http\Resources\Api\V1;

use App\Actions\Alerts\PresentAppOutlookReport;
use App\Enums\AlertCategory;
use App\Models\DeliveryLog;
use App\Models\OutlookReportRun;
use App\Support\Units;
use Illuminate\Http\Request;

/**
 * An alert's detail: the feed entry plus the rule and each channel's own label.
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
            'outlook_report' => $this->outlookReport($request),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function outlookReport(Request $request): ?array
    {
        $first = $this->resource->first();

        if ($first->category !== AlertCategory::OutlookReport->value || $first->batch_key === null) {
            return null;
        }

        $run = OutlookReportRun::where('batch_key', $first->batch_key)->where('user_id', $request->user()->id)->first();

        return $run === null ? null : app(PresentAppOutlookReport::class)($run);
    }

    /**
     * @return array<string, mixed>
     */
    protected function channel(DeliveryLog $row): array
    {
        return [...parent::channel($row), 'label' => $row->notificationChannel?->label];
    }
}
