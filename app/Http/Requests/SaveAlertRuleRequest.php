<?php

namespace App\Http\Requests;

use App\Actions\Alerts\BuildAlertRuleOptions;
use App\Enums\AlertCategory;
use App\Enums\MatchingMode;
use App\Enums\MessageBodyStyle;
use App\Enums\ThresholdDirection;
use App\Enums\ThresholdTimeframe;
use App\Enums\ThresholdVariable;
use App\Enums\Tier;
use App\Models\Product;
use App\Rules\KnownUgcCode;
use App\Settings\TierThresholdSettings;
use App\Support\Units;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The dashboard form's rules for an alert rule, per category. The body is in the subscriber's
 * own units (threshold_value, radius). ruleData() returns what SaveAlertRule reads.
 */
class SaveAlertRuleRequest extends FormRequest
{
    private const LOCATION_MODES = ['location', 'polygon', 'risk_area'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $user = $this->user();
        $category = AlertCategory::tryFrom((string) $this->input('category'));

        $rules = [
            'category' => ['required', Rule::enum(AlertCategory::class)],
            'name' => ['nullable', 'string', 'max:255'],
            'channel_ids' => ['required', 'array', 'min:1'],
            'channel_ids.*' => ['integer', Rule::exists('notification_channels', 'id')->where('user_id', $user->id)],
            'quiet_hours_start' => ['nullable', 'date_format:H:i'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i'],
        ];

        if ($user->tier() !== Tier::Free) {
            $rules['message_body_style'] = ['nullable', Rule::enum(MessageBodyStyle::class)];
            $rules['custom_subject_template'] = ['nullable', 'string', 'max:255'];

            if (in_array($category, [AlertCategory::WeatherAlerts, AlertCategory::SpcWpc], true)) {
                $rules['alert_template_id'] = ['nullable', Rule::exists('alert_templates', 'id')->where('user_id', $user->id)->where('category', $category->value)];
            }
        }

        return [...$rules, ...match ($category) {
            AlertCategory::WeatherAlerts, AlertCategory::SpcWpc => $this->productRules($category),
            AlertCategory::Threshold => $this->thresholdRules(),
            AlertCategory::DailySummary => $this->scheduledRules(),
            AlertCategory::Lightning => $this->lightningRules(),
            null => [],
        }];
    }

    /**
     * What SaveAlertRule reads: the validated fields, with optional ones present as null (a PUT
     * replaces the rule), fields that the matching mode does not use emptied, and `radius`
     * renamed to the form's `radius_miles` key.
     *
     * @return array<string, mixed>
     */
    public function ruleData(): array
    {
        $data = [
            'name' => null, 'quiet_hours_start' => null, 'quiet_hours_end' => null,
            'custom_subject_template' => null, 'alert_template_id' => null,
            ...$this->validated(),
        ];

        $mode = $data['matching_mode'] ?? null;

        if (! in_array($mode, self::LOCATION_MODES, true)) {
            $data['location_ids'] = [];
        }

        if ($mode !== MatchingMode::Wfo->value) {
            $data['wfo'] = null;
        }

        if ($mode !== MatchingMode::CountyZone->value) {
            $data['county_zone_codes'] = null;
        }

        if (array_key_exists('radius', $data)) {
            $data['radius_miles'] = $data['radius'];
            unset($data['radius']);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function productRules(AlertCategory $category): array
    {
        $mode = $this->input('matching_mode');
        $needsLocations = in_array($mode, self::LOCATION_MODES, true);

        return [
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', Rule::exists('products', 'id')->where(fn ($query) => $category === AlertCategory::SpcWpc
                ? $query->where('family', 'spc_wpc')
                : $query->whereIn('family', BuildAlertRuleOptions::WEATHER_FAMILIES))],
            'matching_mode' => ['required', Rule::in($this->allowedModes($category))],
            'location_ids' => $needsLocations ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'location_ids.*' => ['integer', Rule::exists('locations', 'id')->where('user_id', $this->user()->id)],
            'wfo' => $mode === MatchingMode::Wfo->value ? ['required', 'string', Rule::exists('cwa_boundaries', 'cwa')] : ['nullable'],
            'county_zone_codes' => $mode === MatchingMode::CountyZone->value ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'county_zone_codes.*' => ['string', new KnownUgcCode],
            ...($category === AlertCategory::SpcWpc ? [
                'risk_levels' => ['nullable', 'array'],
                'risk_levels.*' => ['string', Rule::in($this->riskLevelOptions())],
            ] : []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function thresholdRules(): array
    {
        return [
            'variable' => ['required', Rule::enum(ThresholdVariable::class)],
            'direction' => ['required', Rule::enum(ThresholdDirection::class)],
            'threshold_value' => ['required', 'numeric'],
            'timeframe' => ['required', Rule::enum(ThresholdTimeframe::class)],
            ...$this->scheduledRules(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduledRules(): array
    {
        return [
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('user_id', $this->user()->id)],
            'delivery_time' => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lightningRules(): array
    {
        $units = Units::for($this->user());

        return [
            'location_id' => ['required', 'integer', Rule::exists('locations', 'id')->where('user_id', $this->user()->id)],
            'radius' => [
                'required', 'integer',
                'min:'.$units->minDistanceDisplay(app(TierThresholdSettings::class)->lightning_min_radius_miles),
                'max:'.$units->maxDistanceDisplay(50),
            ],
        ];
    }

    /**
     * Weather Alerts may use every mode but Risk area. SPC & WPC offers Nationwide plus Risk area
     * when a selected product carries risk levels, otherwise Nationwide plus Polygon (the
     * dashboard form's own rule).
     *
     * @return list<string>
     */
    private function allowedModes(AlertCategory $category): array
    {
        if ($category === AlertCategory::SpcWpc) {
            $hasRiskLevels = Product::query()->whereIn('id', $this->selectedProductIds())->whereNotNull('risk_level_options')->exists();

            return $hasRiskLevels
                ? [MatchingMode::Nationwide->value, MatchingMode::RiskArea->value]
                : [MatchingMode::Nationwide->value, MatchingMode::Polygon->value];
        }

        return collect(MatchingMode::cases())
            ->reject(fn (MatchingMode $mode): bool => $mode === MatchingMode::RiskArea)
            ->map(fn (MatchingMode $mode): string => $mode->value)
            ->values()
            ->all();
    }

    /**
     * @return list<int>
     */
    private function selectedProductIds(): array
    {
        return collect($this->input('product_ids', []))->flatten()->filter(fn ($id): bool => is_numeric($id))->map(fn ($id): int => (int) $id)->unique()->values()->all();
    }

    /**
     * Every level any SPC & WPC product offers (any status: a rule on a retired product keeps its levels).
     *
     * @return list<string>
     */
    private function riskLevelOptions(): array
    {
        return Product::query()->where('family', 'spc_wpc')->whereNotNull('risk_level_options')
            ->pluck('risk_level_options')->flatten()->unique()->values()->all();
    }
}
