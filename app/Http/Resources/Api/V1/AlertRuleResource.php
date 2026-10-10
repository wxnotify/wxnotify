<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AlertRule;
use App\Support\AlertRuleFormUnits;
use App\Support\Units;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An alert rule as the app's editor needs it, in the subscriber's own units. Carries ids only for
 * relations, never a channel destination or URL.
 *
 * @property AlertRule $resource
 */
class AlertRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rule = $this->resource;
        $units = Units::for($request->user());
        $display = AlertRuleFormUnits::toDisplay([
            'variable' => $rule->variable?->value,
            'threshold_value' => $rule->threshold_value,
            'radius_miles' => $rule->radius_miles,
        ], $units);

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'display_name' => $rule->displayName($units->system),
            'category' => $rule->category->value,
            'category_label' => $rule->category->label(),
            'is_active' => (bool) $rule->is_active,
            'matching_mode' => $rule->matching_mode?->value,
            'product_ids' => $rule->products->pluck('id')->values()->all(),
            'location_ids' => $rule->locations->pluck('id')->values()->all(),
            'location_id' => $rule->location_id,
            'wfo' => $rule->wfo,
            'county_zone_codes' => filled($rule->county_zone_codes) ? explode(',', $rule->county_zone_codes) : [],
            'risk_levels' => $rule->risk_levels ?? [],
            'variable' => $rule->variable?->value,
            'direction' => $rule->direction?->value,
            'threshold_value' => is_numeric($display['threshold_value']) ? (float) $display['threshold_value'] : null,
            'threshold_unit' => $rule->variable?->unit($units->system),
            'timeframe' => $rule->timeframe?->value,
            'radius' => is_numeric($display['radius_miles']) ? (int) $display['radius_miles'] : null,
            'radius_unit' => $units->distanceUnit(),
            'delivery_time' => $this->clock($rule->delivery_time),
            'quiet_hours_start' => $this->clock($rule->quiet_hours_start),
            'quiet_hours_end' => $this->clock($rule->quiet_hours_end),
            'message_body_style' => $rule->message_body_style?->value,
            'custom_subject_template' => $rule->custom_subject_template,
            'alert_template_id' => $rule->alert_template_id,
            'channel_ids' => $rule->notificationChannels->pluck('id')->values()->all(),
        ];
    }

    private function clock(mixed $time): ?string
    {
        return blank($time) ? null : substr((string) $time, 0, 5);
    }
}
