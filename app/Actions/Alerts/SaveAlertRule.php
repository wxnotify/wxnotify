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
 * Input uses the dashboard form's keys, in the user's units (see the keys read below). A
 * product-based rule matches each product by that product's own settings (the pivot's mode, WFO
 * and zones, and `alert_rule_product_location`): they come from `product_settings`, or from the
 * flat `product_ids` with `matching_mode`, `location_ids`, `wfo` and `county_zone_codes`, which
 * apply to every product. The rule's own `matching_mode`, `wfo`, `county_zone_codes` and
 * `alert_rule_location` are no longer written for such a rule. A stored product-based rule saved
 * with neither key keeps its products, their settings and its product-based columns as stored.
 */
class SaveAlertRule
{
    /** The category-specific columns each category owns; every other one is cleared on save. */
    private const CATEGORY_COLUMNS = [
        'weather_alerts' => [],
        'spc_wpc' => ['risk_levels'],
        'threshold' => ['variable', 'direction', 'threshold_value', 'timeframe', 'location_id', 'delivery_time'],
        'daily_summary' => ['location_id', 'delivery_time'],
        'lightning' => ['location_id', 'radius_miles'],
    ];

    private const ALL_CATEGORY_COLUMNS = [
        'matching_mode', 'wfo', 'county_zone_codes', 'risk_levels', 'variable', 'direction',
        'threshold_value', 'timeframe', 'location_id', 'delivery_time', 'radius_miles',
    ];

    private const CATEGORIES_WITH_PRODUCTS = ['weather_alerts', 'spc_wpc'];

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
        $keepsProducts = $stored !== null && self::usesProducts($stored->category) && $this->keepsProducts($category, $data);

        $attributes = Arr::only($data, ['name', 'quiet_hours_start', 'quiet_hours_end', ...self::ALL_CATEGORY_COLUMNS]);
        $attributes['category'] = $category->value;

        foreach (['name', 'quiet_hours_start', 'quiet_hours_end'] as $optional) {
            if (array_key_exists($optional, $attributes) && ! filled($attributes[$optional])) {
                $attributes[$optional] = null;
            }
        }

        if ($keepsProducts) {
            $attributes = Arr::except($attributes, self::ALL_CATEGORY_COLUMNS);
        } else {
            foreach (self::ALL_CATEGORY_COLUMNS as $column) {
                if (! in_array($column, $owned, true)) {
                    $attributes[$column] = null;
                }
            }

            if ($category === AlertCategory::SpcWpc) {
                $attributes['risk_levels'] = SpcWpcRiskLevels::flatten($data['risk_levels'] ?? []);
            }
        }

        $attributes = [...$attributes, ...$this->messageCustomisation($user, $category, $data, $stored)];

        if ($stored === null) {
            $attributes['user_id'] = $user->id;
        }

        return AlertRuleFormUnits::toStorage($attributes, Units::for($user), $stored);
    }

    /**
     * Pivot syncs. A product-based rule's products, their settings and their own locations are
     * replaced together (left alone on the keep path); every other category has none, and the
     * rule's own location list is no longer used. A product's locations are only ever the rule
     * owner's own (others are dropped here as well as refused by validation). The channel list
     * always applies.
     *
     * @param  array<string, mixed>  $data
     */
    public function syncRelations(AlertRule $rule, array $data): void
    {
        DB::transaction(function () use ($rule, $data): void {
            if (! $this->keepsProducts($rule->category, $data)) {
                $settings = self::usesProducts($rule->category) ? ($this->productSettings($data) ?? []) : [];

                $rule->products()->sync(collect($settings)->mapWithKeys(fn (array $entry): array => [$entry['product_id'] => [
                    'matching_mode' => $entry['matching_mode'],
                    'wfo' => $entry['wfo'],
                    'county_zone_codes' => $entry['county_zone_codes'],
                ]])->all());

                // Only the rule owner's own locations, whatever the caller validated.
                $ownedLocationIds = DB::table('locations')->where('user_id', $rule->user_id)
                    ->whereIn('id', collect($settings)->flatMap(fn (array $entry): array => $entry['location_ids'])->unique()->all())
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();

                DB::table('alert_rule_product_location')->where('alert_rule_id', $rule->id)->delete();
                DB::table('alert_rule_product_location')->insert(collect($settings)->flatMap(fn (array $entry): array => array_map(
                    fn (int $locationId): array => ['alert_rule_id' => $rule->id, 'product_id' => $entry['product_id'], 'location_id' => $locationId],
                    array_values(array_intersect($entry['location_ids'], $ownedLocationIds)),
                ))->all());

                $rule->locations()->sync([]);
            }

            $rule->notificationChannels()->sync($data['channel_ids'] ?? []);
        });
    }

    /**
     * Each watched product's matching settings, as stored: `wfo` only for the WFO mode, the
     * county/zone codes (comma-joined) only for County/Zone, and the de-duplicated locations only
     * for the location-based modes. Built from `product_settings`, else from the flat
     * `product_ids` with one shared mode; null when the input carries neither (the keep path).
     *
     * @param  array<string, mixed>  $data
     * @return list<array{product_id: int, matching_mode: ?string, location_ids: list<int>, wfo: ?string, county_zone_codes: ?string}>|null
     */
    public function productSettings(array $data): ?array
    {
        if (array_key_exists('product_settings', $data)) {
            $entries = collect($data['product_settings'] ?? []);
        } elseif (array_key_exists('product_ids', $data)) {
            $entries = collect($data['product_ids'] ?? [])->flatten()->map(fn ($id): array => [
                'product_id' => $id,
                'matching_mode' => $data['matching_mode'] ?? null,
                'location_ids' => $data['location_ids'] ?? [],
                'wfo' => $data['wfo'] ?? null,
                'county_zone_codes' => $data['county_zone_codes'] ?? null,
            ]);
        } else {
            return null;
        }

        return $entries
            ->filter(fn ($entry): bool => is_array($entry) && is_numeric($entry['product_id'] ?? null))
            ->unique(fn (array $entry): int => (int) $entry['product_id'])
            ->map(fn (array $entry): array => self::normaliseEntry($entry))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array{product_id: int, matching_mode: ?string, location_ids: list<int>, wfo: ?string, county_zone_codes: ?string}
     */
    private static function normaliseEntry(array $entry): array
    {
        $mode = MatchingMode::tryFrom((string) ($entry['matching_mode'] ?? ''));
        $usesLocations = in_array($mode, [MatchingMode::Location, MatchingMode::Polygon, MatchingMode::RiskArea], true);
        $codes = $mode === MatchingMode::CountyZone ? ($entry['county_zone_codes'] ?? null) : null;

        return [
            'product_id' => (int) $entry['product_id'],
            'matching_mode' => $mode?->value,
            'location_ids' => $usesLocations
                ? collect($entry['location_ids'] ?? [])->filter(fn ($id): bool => is_numeric($id))->map(fn ($id): int => (int) $id)->unique()->values()->all()
                : [],
            'wfo' => $mode === MatchingMode::Wfo && filled($entry['wfo'] ?? null) ? (string) $entry['wfo'] : null,
            'county_zone_codes' => is_array($codes) ? (filled($codes) ? implode(',', $codes) : null) : (filled($codes) ? (string) $codes : null),
        ];
    }

    /**
     * The keep path: a product-based rule saved with neither `product_settings` nor `product_ids`.
     *
     * @param  array<string, mixed>  $data
     */
    private function keepsProducts(AlertCategory $category, array $data): bool
    {
        return self::usesProducts($category) && $this->productSettings($data) === null;
    }

    private static function usesProducts(?AlertCategory $category): bool
    {
        return in_array($category?->value, self::CATEGORIES_WITH_PRODUCTS, true);
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
