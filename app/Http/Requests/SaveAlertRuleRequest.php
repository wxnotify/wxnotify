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
use App\Support\ProductMatching;
use App\Support\Units;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * The dashboard form's rules for an alert rule, per category. The body is in the subscriber's
 * own units (threshold_value, radius). ruleData() returns what SaveAlertRule reads.
 */
class SaveAlertRuleRequest extends FormRequest
{
    private const LOCATION_MODES = ['location', 'polygon', 'risk_area'];

    /** The flat matching input that product_settings replaces (never sent together with it). */
    private const FLAT_PRODUCT_KEYS = ['product_ids', 'matching_mode', 'location_ids', 'wfo', 'county_zone_codes'];

    /** Any of these on a PUT means the client is setting products or matching, not keeping them. */
    private const PRODUCT_KEYS = ['product_settings', 'risk_levels', ...self::FLAT_PRODUCT_KEYS];

    /** @var array<int, list<string>>|null */
    private ?array $riskLevelOptionsByProduct = null;

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
     * replaces the rule), and `radius` renamed to the form's `radius_miles` key.
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
        if ($this->keepsStoredProducts($category)) {
            return [];
        }

        $riskLevelRules = $category === AlertCategory::SpcWpc ? [
            'risk_levels' => ['nullable', 'array'],
            'risk_levels.*' => ['string', Rule::in($this->riskLevelOptions())],
        ] : [];

        return $this->has('product_settings')
            ? [...$this->productSettingsRules($category), ...$riskLevelRules]
            : [...$this->flatProductRules($category), ...$riskLevelRules];
    }

    /**
     * The keep path: a PUT of a product-based rule that carries none of the product or matching
     * fields leaves the stored products and their settings alone. Safe only while the category is
     * unchanged; otherwise the old family's products would carry into the new category.
     */
    private function keepsStoredProducts(AlertCategory $category): bool
    {
        if (! $this->isMethod('PUT') || $this->hasAny(self::PRODUCT_KEYS)) {
            return false;
        }

        $stored = $this->user()->alertRules()->find($this->route('id'));

        return $stored !== null && $stored->category === $category;
    }

    /**
     * One entry per watched product, each with its own mode and that mode's inputs (and, for SPC & WPC,
     * optionally its own `risk_levels`, which win over the flat list for that product). A mode must be
     * one the category accepts, so a client echoing back a read rule's `mixed` is refused rather
     * than stored as "inherit".
     *
     * @return array<string, mixed>
     */
    private function productSettingsRules(AlertCategory $category): array
    {
        $locationModes = implode(',', self::LOCATION_MODES);

        return [
            'product_settings' => ['required', 'array', 'min:1', 'prohibits:'.implode(',', self::FLAT_PRODUCT_KEYS)],
            'product_settings.*.product_id' => ['required', 'integer', 'distinct', $this->productExists($category)],
            'product_settings.*.matching_mode' => ['required', 'string', Rule::in(array_map(fn (MatchingMode $mode): string => $mode->value, ProductMatching::acceptedModes($category)))],
            'product_settings.*.location_ids' => ['required_if:product_settings.*.matching_mode,'.$locationModes, 'nullable', 'array'],
            'product_settings.*.location_ids.*' => ['integer', Rule::exists('locations', 'id')->where('user_id', $this->user()->id)],
            'product_settings.*.wfo' => ['exclude_unless:product_settings.*.matching_mode,'.MatchingMode::Wfo->value, 'required', 'string', Rule::exists('cwa_boundaries', 'cwa')],
            'product_settings.*.county_zone_codes' => ['required_if:product_settings.*.matching_mode,'.MatchingMode::CountyZone->value, 'nullable', 'array'],
            'product_settings.*.county_zone_codes.*' => ['string', new KnownUgcCode],
            ...($category === AlertCategory::SpcWpc ? [
                'product_settings.*.risk_levels' => ['nullable', 'array'],
                'product_settings.*.risk_levels.*' => ['string', $this->offeredByEntryProduct(...)],
            ] : []),
        ];
    }

    /**
     * The original flat input: one shared mode for every product.
     *
     * @return array<string, mixed>
     */
    private function flatProductRules(AlertCategory $category): array
    {
        $mode = $this->input('matching_mode');
        $needsLocations = in_array($mode, self::LOCATION_MODES, true);

        return [
            'product_ids' => ['required', 'array', 'min:1'],
            'product_ids.*' => ['integer', $this->productExists($category)],
            'matching_mode' => ['required', Rule::in($this->allowedModes($category))],
            'location_ids' => $needsLocations ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'location_ids.*' => ['integer', Rule::exists('locations', 'id')->where('user_id', $this->user()->id)],
            'wfo' => $mode === MatchingMode::Wfo->value ? ['required', 'string', Rule::exists('cwa_boundaries', 'cwa')] : ['nullable'],
            'county_zone_codes' => $mode === MatchingMode::CountyZone->value ? ['required', 'array', 'min:1'] : ['nullable', 'array'],
            'county_zone_codes.*' => ['string', new KnownUgcCode],
        ];
    }

    private function productExists(AlertCategory $category): Exists
    {
        return Rule::exists('products', 'id')->where(fn ($query) => $category === AlertCategory::SpcWpc
            ? $query->where('family', 'spc_wpc')
            : $query->whereIn('family', BuildAlertRuleOptions::WEATHER_FAMILIES));
    }

    /**
     * @return array<string, mixed>
     */
    private function thresholdRules(): array
    {
        return [
            'variable' => ['required', Rule::enum(ThresholdVariable::class)],
            'direction' => ['required', Rule::enum(ThresholdDirection::class)],
            'threshold_value' => ['required', 'numeric', 'between:-9999,9999'],
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
        return collect($this->riskLevelOptionsByProduct())->flatten()->unique()->values()->all();
    }

    /**
     * Each SPC & WPC product's own levels, by id, read once per request.
     *
     * @return array<int, list<string>>
     */
    private function riskLevelOptionsByProduct(): array
    {
        return $this->riskLevelOptionsByProduct ??= Product::query()->where('family', 'spc_wpc')->whereNotNull('risk_level_options')
            ->pluck('risk_level_options', 'id')->map(fn (mixed $options): array => is_array($options) ? $options : [])->all();
    }

    /**
     * A `product_settings.{n}.risk_levels.{m}` level must be one that entry's own product offers (a product
     * without risk levels takes none).
     */
    private function offeredByEntryProduct(string $attribute, mixed $value, Closure $fail): void
    {
        $index = explode('.', $attribute)[1] ?? '';
        $options = $this->riskLevelOptionsByProduct()[(int) $this->input("product_settings.{$index}.product_id")] ?? [];

        if (! in_array($value, $options, true)) {
            $fail('The selected :attribute is not a risk level of this product.');
        }
    }
}
