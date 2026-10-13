<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AlertRule;
use App\Models\Product;
use App\Support\AlertRuleFormUnits;
use App\Support\ProductMatchSettings;
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
        $matching = $this->matching($rule);

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'display_name' => $rule->displayName($units->system),
            'category' => $rule->category->value,
            'category_label' => $rule->category->label(),
            'is_active' => (bool) $rule->is_active,
            'matching_mode' => $matching['matching_mode'],
            'product_ids' => $rule->products->pluck('id')->values()->all(),
            'location_ids' => $matching['location_ids'],
            'location_id' => $rule->location_id,
            'wfo' => $matching['wfo'],
            'county_zone_codes' => $matching['county_zone_codes'],
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

    /**
     * The rule's matching as one set of flat fields, from each watched product's settings
     * (AlertRule::settingsFor()): their shared value when every product matches the same way,
     * else `mixed` with the other fields empty; a rule with no products reads its own columns.
     *
     * @return array{matching_mode: ?string, location_ids: list<int>, wfo: ?string, county_zone_codes: list<string>}
     */
    private function matching(AlertRule $rule): array
    {
        $distinct = $rule->products
            ->map(fn (Product $product): ?ProductMatchSettings => $rule->settingsFor($product->id))
            ->filter()
            ->map(fn (ProductMatchSettings $settings): array => [
                'matching_mode' => $settings->mode?->value,
                'location_ids' => $settings->locations->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                'wfo' => $settings->wfo,
                'county_zone_codes' => $settings->countyZoneCodes,
            ])
            ->unique(fn (array $fields): string => serialize($fields))
            ->values();

        return match ($distinct->count()) {
            0 => [
                'matching_mode' => $rule->matching_mode?->value,
                'location_ids' => $rule->locations->pluck('id')->values()->all(),
                'wfo' => $rule->wfo,
                'county_zone_codes' => filled($rule->county_zone_codes) ? explode(',', $rule->county_zone_codes) : [],
            ],
            1 => $distinct->first(),
            default => ['matching_mode' => 'mixed', 'location_ids' => [], 'wfo' => null, 'county_zone_codes' => []],
        };
    }

    private function clock(mixed $time): ?string
    {
        return blank($time) ? null : substr((string) $time, 0, 5);
    }
}
