<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\AlertCategory;
use App\Models\AlertRule;
use App\Models\Product;
use App\Support\AlertRuleFormUnits;
use App\Support\ProductMatchSettings;
use App\Support\Units;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Arr;

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
        $productSettings = $this->productSettings($rule);
        $matching = $this->matching($rule, $productSettings);

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'display_name' => $rule->displayName($units->system),
            'category' => $rule->category->value,
            'category_label' => $rule->category->label(),
            'is_active' => (bool) $rule->is_active,
            'matching_mode' => $matching['matching_mode'],
            'product_ids' => $rule->products->pluck('id')->values()->all(),
            'product_settings' => $productSettings,
            'location_ids' => $matching['location_ids'],
            'location_id' => $rule->location_id,
            'wfo' => $matching['wfo'],
            'county_zone_codes' => $matching['county_zone_codes'],
            'risk_levels' => $this->riskLevels($rule),
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
     * Every watched product's matching settings (AlertRule::settingsFor()), in the order the rule lists its products,
     * with an SPC & WPC product's own risk levels.
     *
     * @return list<array{product_id: int, matching_mode: ?string, location_ids: list<int>, wfo: ?string, county_zone_codes: list<string>, risk_levels?: list<string>}>
     */
    private function productSettings(AlertRule $rule): array
    {
        return $rule->products
            ->map(fn (Product $product): array => [
                ...$this->entry($product->id, $rule->settingsFor($product->id)),
                ...($rule->category === AlertCategory::SpcWpc ? ['risk_levels' => $rule->riskLevelsFor($product->id)] : []),
            ])
            ->values()
            ->all();
    }

    /**
     * The flat `risk_levels` older clients read: every level the rule selected for any of its products
     * (AlertRule::riskLevelsFor()); each product's own levels are in its `product_settings` entry.
     *
     * @return list<string>
     */
    private function riskLevels(AlertRule $rule): array
    {
        if ($rule->category !== AlertCategory::SpcWpc) {
            return [];
        }

        return $rule->products->flatMap(fn (Product $product): array => $rule->riskLevelsFor($product->id))->unique()->values()->all();
    }

    /**
     * @return array{product_id: int, matching_mode: ?string, location_ids: list<int>, wfo: ?string, county_zone_codes: list<string>}
     */
    private function entry(int $productId, ProductMatchSettings $settings): array
    {
        return [
            'product_id' => $productId,
            'matching_mode' => $settings->mode?->value,
            'location_ids' => $settings->locations->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            'wfo' => $settings->wfo,
            'county_zone_codes' => $settings->countyZoneCodes,
        ];
    }

    /**
     * The rule's matching as one set of flat fields, from the products' settings: their shared value
     * when every product matches the same way, else `mixed` with the other fields empty; a rule with
     * no products reads its own columns.
     *
     * This compares the FULL settings (mode, locations, office and zone codes; never the risk levels, which have
     * their own flat field) and must stay lossless
     * for the mobile app: two products that share a mode but differ in locations read `mixed`, so the
     * app never edits and re-saves a rule as if it were uniform. Do not unify it with
     * AlertRulesTable::locationLabels() (My Alerts), which deliberately compares the mode only.
     *
     * @param  list<array{product_id: int, matching_mode: ?string, location_ids: list<int>, wfo: ?string, county_zone_codes: list<string>}>  $productSettings
     * @return array{matching_mode: ?string, location_ids: list<int>, wfo: ?string, county_zone_codes: list<string>}
     */
    private function matching(AlertRule $rule, array $productSettings): array
    {
        $distinct = collect($productSettings)
            ->map(fn (array $entry): array => Arr::except($entry, ['product_id', 'risk_levels']))
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
