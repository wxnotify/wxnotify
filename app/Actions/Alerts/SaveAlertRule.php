<?php

namespace App\Actions\Alerts;

use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Enums\MessageBodyStyle;
use App\Enums\Tier;
use App\Models\AlertRule;
use App\Models\User;
use App\Support\AlertRuleFormUnits;
use App\Support\SpcWpcRiskLevels;
use App\Support\Units;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Support\CauserResolver;

/**
 * Persists an alert rule the way the dashboard's Create and Edit pages always have, for both the
 * dashboard and the mobile API: the user's units become the stored imperial values (without
 * drifting an untouched number), SPC & WPC risk levels are stored flat, columns that belong to
 * another category are cleared, and the product, location and channel pivots are synced.
 * Validation stays with the caller (the form's field rules, or SaveAlertRuleRequest).
 *
 * Input uses the dashboard form's keys, in the user's units (see the keys read below).
 */
class SaveAlertRule
{
    /** The category-specific columns each category owns; every other one is cleared on save. */
    private const CATEGORY_COLUMNS = [
        'weather_alerts' => ['matching_mode', 'wfo', 'county_zone_codes'],
        'spc_wpc' => ['matching_mode', 'risk_levels'],
        'threshold' => ['variable', 'direction', 'threshold_value', 'timeframe', 'location_id', 'delivery_time'],
        'daily_summary' => ['location_id', 'delivery_time'],
        'lightning' => ['location_id', 'radius_miles'],
    ];

    private const ALL_CATEGORY_COLUMNS = [
        'matching_mode', 'wfo', 'county_zone_codes', 'risk_levels', 'variable', 'direction',
        'threshold_value', 'timeframe', 'location_id', 'delivery_time', 'radius_miles',
    ];

    private const CATEGORIES_WITH_PRODUCTS = ['weather_alerts', 'spc_wpc'];

    /** The matching modes that match against the subscriber's saved locations. */
    private const MODES_WITH_LOCATIONS = ['location', 'polygon', 'risk_area'];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __invoke(User $user, array $data, ?AlertRule $rule = null): AlertRule
    {
        return app(CauserResolver::class)->withCauser($user, fn (): AlertRule => DB::transaction(function () use ($user, $data, $rule): AlertRule {
            $attributes = $this->attributes($user, $data, $rule);

            if ($rule === null) {
                $rule = AlertRule::create($attributes);
            } else {
                $rule->update($attributes);
            }

            $this->syncRelations($rule, $data);

            return $rule->refresh();
        }));
    }

    /**
     * The columns to persist. Only keys the caller supplied are written, except that every
     * category-specific column the saved category does not own is set to null.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function attributes(User $user, array $data, ?AlertRule $stored = null): array
    {
        $category = AlertCategory::from((string) ($data['category'] ?? $stored?->category?->value));
        $owned = self::CATEGORY_COLUMNS[$category->value];

        $attributes = Arr::only($data, ['name', 'quiet_hours_start', 'quiet_hours_end', ...self::ALL_CATEGORY_COLUMNS]);
        $attributes['category'] = $category->value;

        foreach (['name', 'quiet_hours_start', 'quiet_hours_end'] as $optional) {
            if (array_key_exists($optional, $attributes) && ! filled($attributes[$optional])) {
                $attributes[$optional] = null;
            }
        }

        foreach (self::ALL_CATEGORY_COLUMNS as $column) {
            if (! in_array($column, $owned, true)) {
                $attributes[$column] = null;
            }
        }

        $mode = $attributes['matching_mode'] ?? null;

        if (in_array('matching_mode', $owned, true)) {
            if ($mode !== MatchingMode::Wfo->value) {
                $attributes['wfo'] = null;
            }

            $codes = $mode === MatchingMode::CountyZone->value ? ($data['county_zone_codes'] ?? null) : null;
            $attributes['county_zone_codes'] = is_array($codes) ? (filled($codes) ? implode(',', $codes) : null) : (filled($codes) ? $codes : null);
        }

        if ($category === AlertCategory::SpcWpc) {
            $attributes['risk_levels'] = SpcWpcRiskLevels::flatten($data['risk_levels'] ?? []);
        }

        $attributes = [...$attributes, ...$this->messageCustomisation($user, $category, $data, $stored)];

        if ($stored === null) {
            $attributes['user_id'] = $user->id;
        }

        return AlertRuleFormUnits::toStorage($attributes, Units::for($user), $stored);
    }

    /**
     * Pivot syncs. Products only apply to the product-based categories, and locations only to a
     * product-based rule whose matching mode uses them; the channel list always applies.
     *
     * @param  array<string, mixed>  $data
     */
    public function syncRelations(AlertRule $rule, array $data): void
    {
        $usesProducts = in_array($rule->category->value, self::CATEGORIES_WITH_PRODUCTS, true);

        $rule->products()->sync($usesProducts
            ? collect($data['product_ids'] ?? [])->flatten()->filter(fn ($id): bool => is_numeric($id))->values()->all()
            : []);
        $usesLocations = $usesProducts && in_array($rule->matching_mode?->value, self::MODES_WITH_LOCATIONS, true);

        $rule->locations()->sync($usesLocations ? ($data['location_ids'] ?? []) : []);
        $rule->notificationChannels()->sync($data['channel_ids'] ?? []);
    }

    /**
     * Message length, custom subject and template: paid tiers only (the dashboard hides them
     * from Free), and a template only for a category that supports one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function messageCustomisation(User $user, AlertCategory $category, array $data, ?AlertRule $stored): array
    {
        $attributes = [];

        if ($user->tier() !== Tier::Free) {
            if (array_key_exists('custom_subject_template', $data)) {
                $attributes['custom_subject_template'] = filled($data['custom_subject_template']) ? $data['custom_subject_template'] : null;
            }

            if (filled($data['message_body_style'] ?? null)) {
                $attributes['message_body_style'] = $data['message_body_style'];
            } elseif ($stored === null) {
                $attributes['message_body_style'] = MessageBodyStyle::Full->value;
            }

            if (array_key_exists('alert_template_id', $data)) {
                $attributes['alert_template_id'] = filled($data['alert_template_id']) ? $data['alert_template_id'] : null;
            }
        }

        if (! in_array($category->value, self::CATEGORIES_WITH_PRODUCTS, true)) {
            $attributes['alert_template_id'] = null;
        }

        return $attributes;
    }
}
