<?php

namespace App\Support;

/**
 * The alert rule form's logic on the phone, as plain data in and out: which fields a category and
 * matching mode show, which matching modes the selected products allow, how a stored rule becomes
 * a draft, how a draft becomes the API body, and the required-field checks. It mirrors the
 * dashboard form and the server's request rules so the editor can guide the subscriber, but the
 * server stays the authority and re-validates everything.
 *
 * A draft uses the API's own keys; empty values are '', [] or null.
 */
final class RuleFormPresenter
{
    private const PRODUCT_CATEGORIES = ['weather_alerts', 'spc_wpc'];

    private const LOCATION_MODES = ['location', 'polygon', 'risk_area'];

    /** The server refuses a threshold beyond this in the subscriber's own units (the column is decimal(6,1)). */
    private const MAX_THRESHOLD_VALUE = 9999;

    /**
     * @param  array<string, mixed>  $options  the /alert-rule-options payload
     */
    public function __construct(private readonly array $options) {}

    /**
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public function categories(): array
    {
        return $this->options['categories'] ?? [];
    }

    /**
     * @return array<string, mixed>
     */
    public function blankDraft(string $category = ''): array
    {
        return [
            'category' => $category,
            'name' => '',
            'product_ids' => [],
            'matching_mode' => '',
            'location_ids' => [],
            'location_id' => null,
            'wfo' => '',
            'county_zone_codes' => [],
            'risk_levels' => [],
            'variable' => '',
            'direction' => '',
            'threshold_value' => '',
            'timeframe' => '',
            'delivery_time' => '',
            'radius' => $category === 'lightning' ? (string) ($this->options['lightning']['default'] ?? '') : '',
            'quiet_hours_start' => '',
            'quiet_hours_end' => '',
            'message_body_style' => '',
            'custom_subject_template' => '',
            'alert_template_id' => null,
            'channel_ids' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $rule  a rule as the API returns it
     * @return array<string, mixed>
     */
    public function draftFromRule(array $rule): array
    {
        $text = fn (string $key): string => (string) ($rule[$key] ?? '');
        $number = fn (string $key): string => isset($rule[$key]) ? rtrim(rtrim(number_format((float) $rule[$key], 4, '.', ''), '0'), '.') : '';

        return [
            ...$this->blankDraft($text('category')),
            'name' => $text('name'),
            'product_ids' => array_values($rule['product_ids'] ?? []),
            'matching_mode' => $text('matching_mode'),
            'location_ids' => array_values($rule['location_ids'] ?? []),
            'location_id' => $rule['location_id'] ?? null,
            'wfo' => $text('wfo'),
            'county_zone_codes' => array_values($rule['county_zone_codes'] ?? []),
            'risk_levels' => array_values($rule['risk_levels'] ?? []),
            'variable' => $text('variable'),
            'direction' => $text('direction'),
            'threshold_value' => $number('threshold_value'),
            'timeframe' => $text('timeframe'),
            'delivery_time' => $text('delivery_time'),
            'radius' => $number('radius'),
            'quiet_hours_start' => $text('quiet_hours_start'),
            'quiet_hours_end' => $text('quiet_hours_end'),
            'message_body_style' => $text('message_body_style'),
            'custom_subject_template' => $text('custom_subject_template'),
            'alert_template_id' => $rule['alert_template_id'] ?? null,
            'channel_ids' => array_values($rule['channel_ids'] ?? []),
        ];
    }

    /**
     * Keeps what every category shares (name, quiet hours, channels, message style and subject);
     * the category's own fields start blank, and the template is cleared because it belongs to a category.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function switchCategory(array $draft, string $category): array
    {
        $shared = array_intersect_key($draft, array_flip([
            'name', 'quiet_hours_start', 'quiet_hours_end', 'message_body_style', 'custom_subject_template', 'channel_ids',
        ]));

        return [...$this->blankDraft($category), ...$shared];
    }

    /**
     * The "Set it up" fields to show, in order.
     *
     * @param  array<string, mixed>  $draft
     * @return array<int, string>
     */
    public function fields(array $draft): array
    {
        $mode = (string) $draft['matching_mode'];

        return match ($draft['category']) {
            'weather_alerts', 'spc_wpc' => [
                'products',
                ...($this->riskLevelChoices($draft) !== [] ? ['risk_levels'] : []),
                'matching_mode',
                ...(in_array($mode, self::LOCATION_MODES, true) ? ['locations'] : []),
                ...($mode === 'wfo' ? ['wfo'] : []),
                ...($mode === 'county_zone' ? ['county_zone_codes'] : []),
            ],
            'threshold' => ['variable', 'direction', 'threshold_value', 'timeframe', 'location', 'delivery_time'],
            'daily_summary' => ['location', 'delivery_time'],
            'lightning' => ['location', 'radius'],
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<int, array{category: string, label: string, products: array<int, array<string, mixed>>}>
     */
    public function productGroups(array $draft): array
    {
        return in_array($draft['category'], self::PRODUCT_CATEGORIES, true)
            ? ($this->options['products'][$draft['category']] ?? [])
            : [];
    }

    /**
     * Weather Alerts offer every mode but Risk area. SPC & WPC offer Nationwide plus Risk area when
     * a selected product carries risk levels, otherwise Nationwide plus Polygon (the dashboard's rule).
     *
     * @param  array<string, mixed>  $draft
     * @return array<int, array{value: string, label: string, description: string}>
     */
    public function allowedModes(array $draft): array
    {
        $allowed = match ($draft['category']) {
            'weather_alerts' => ['nationwide', 'location', 'wfo', 'county_zone', 'polygon'],
            'spc_wpc' => $this->riskLevelChoices($draft) !== [] ? ['nationwide', 'risk_area'] : ['nationwide', 'polygon'],
            default => [],
        };

        return array_values(array_filter(
            $this->options['matching_modes'] ?? [],
            fn (array $mode): bool => in_array($mode['value'], $allowed, true),
        ));
    }

    /**
     * Every risk level the selected SPC & WPC products offer, in the products' own order.
     *
     * @param  array<string, mixed>  $draft
     * @return array<int, string>
     */
    public function riskLevelChoices(array $draft): array
    {
        if ($draft['category'] !== 'spc_wpc') {
            return [];
        }

        $levels = [];

        foreach ($this->productGroups($draft) as $group) {
            foreach ($group['products'] as $product) {
                if (in_array($product['id'], $draft['product_ids'], true)) {
                    foreach ($product['risk_levels'] as $level) {
                        $levels[$level] = $level;
                    }
                }
            }
        }

        return array_values($levels);
    }

    /**
     * After a product or mode change: drop a mode the products no longer allow, risk levels they no
     * longer offer, and the picks (locations, office, codes) the current mode does not use.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function reconcile(array $draft): array
    {
        if (! in_array($draft['category'], self::PRODUCT_CATEGORIES, true)) {
            return $draft;
        }

        $allowed = array_column($this->allowedModes($draft), 'value');

        if ($draft['matching_mode'] !== '' && ! in_array($draft['matching_mode'], $allowed, true)) {
            $draft['matching_mode'] = '';
        }

        $draft['risk_levels'] = array_values(array_intersect($draft['risk_levels'], $this->riskLevelChoices($draft)));

        $mode = $draft['matching_mode'];

        if (! in_array($mode, self::LOCATION_MODES, true)) {
            $draft['location_ids'] = [];
        }

        if ($mode !== 'wfo') {
            $draft['wfo'] = '';
        }

        if ($mode !== 'county_zone') {
            $draft['county_zone_codes'] = [];
        }

        return $draft;
    }

    /**
     * The API request body: only the fields the category and mode use, numbers as numbers, blanks as null.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function payload(array $draft): array
    {
        $blank = fn (string $key): mixed => filled($draft[$key]) ? $draft[$key] : null;
        $category = (string) $draft['category'];

        $body = [
            'category' => $category,
            'name' => $blank('name'),
            'channel_ids' => array_values($draft['channel_ids']),
            'quiet_hours_start' => $blank('quiet_hours_start'),
            'quiet_hours_end' => $blank('quiet_hours_end'),
        ];

        if ($this->canCustomizeMessages()) {
            $body['message_body_style'] = $blank('message_body_style');
            $body['custom_subject_template'] = $blank('custom_subject_template');

            if (in_array($category, self::PRODUCT_CATEGORIES, true)) {
                $body['alert_template_id'] = $draft['alert_template_id'];
            }
        }

        return [...$body, ...match ($category) {
            'weather_alerts', 'spc_wpc' => $this->productPayload($draft),
            'threshold' => [
                'variable' => $draft['variable'],
                'direction' => $draft['direction'],
                'threshold_value' => (float) $draft['threshold_value'],
                'timeframe' => $draft['timeframe'],
                'location_id' => $draft['location_id'],
                'delivery_time' => $draft['delivery_time'],
            ],
            'daily_summary' => ['location_id' => $draft['location_id'], 'delivery_time' => $draft['delivery_time']],
            'lightning' => ['location_id' => $draft['location_id'], 'radius' => (int) $draft['radius']],
            default => [],
        }];
    }

    /**
     * Required-field checks that mirror the server's, one short message per field.
     *
     * @param  array<string, mixed>  $draft
     * @return array<string, string>
     */
    public function validate(array $draft): array
    {
        $errors = [];
        $category = (string) $draft['category'];

        if ($category === '') {
            $errors['category'] = 'Choose a kind of alert.';
        }

        if (in_array($category, self::PRODUCT_CATEGORIES, true)) {
            if ($draft['product_ids'] === []) {
                $errors['product_ids'] = 'Select at least one product.';
            }

            if ($draft['matching_mode'] === '') {
                $errors['matching_mode'] = 'Choose how alerts should match.';
            } elseif (in_array($draft['matching_mode'], self::LOCATION_MODES, true) && $draft['location_ids'] === []) {
                $errors['location_ids'] = 'Choose at least one location.';
            } elseif ($draft['matching_mode'] === 'wfo' && $draft['wfo'] === '') {
                $errors['wfo'] = 'Choose an NWS office.';
            } elseif ($draft['matching_mode'] === 'county_zone' && $draft['county_zone_codes'] === []) {
                $errors['county_zone_codes'] = 'Choose at least one county or zone.';
            }
        }

        if ($category === 'threshold') {
            if ($draft['variable'] === '') {
                $errors['variable'] = 'Choose a variable.';
            }

            if ($draft['direction'] === '') {
                $errors['direction'] = 'Choose a direction.';
            }

            if (! is_numeric($draft['threshold_value'])) {
                $errors['threshold_value'] = 'Enter a number.';
            } elseif (abs((float) $draft['threshold_value']) > self::MAX_THRESHOLD_VALUE) {
                $errors['threshold_value'] = 'Enter a value between -'.self::MAX_THRESHOLD_VALUE.' and '.self::MAX_THRESHOLD_VALUE.'.';
            }

            if ($draft['timeframe'] === '') {
                $errors['timeframe'] = 'Choose a timeframe.';
            }
        }

        if (in_array($category, ['threshold', 'daily_summary'], true)) {
            $this->requireLocationAndTime($draft, $errors);
        }

        if ($category === 'lightning') {
            $bounds = $this->radiusBounds();

            if ($draft['location_id'] === null) {
                $errors['location_id'] = 'Choose a location.';
            }

            $radius = filter_var(trim((string) $draft['radius']), FILTER_VALIDATE_INT);

            if ($radius === false || $radius < $bounds['min'] || $radius > $bounds['max']) {
                $errors['radius'] = "Enter a whole-number radius from {$bounds['min']} to {$bounds['max']} {$this->radiusUnit()}.";
            }
        }

        if ($draft['channel_ids'] === []) {
            $errors['channel_ids'] = 'Choose at least one channel.';
        }

        return $errors;
    }

    public function canCustomizeMessages(): bool
    {
        return (bool) ($this->options['tier']['can_customize_messages'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    public function unitLabel(array $draft): ?string
    {
        foreach ($this->options['thresholds']['variables'] ?? [] as $variable) {
            if ($variable['value'] === $draft['variable']) {
                return $variable['unit'];
            }
        }

        return null;
    }

    public function radiusUnit(): string
    {
        return (string) ($this->options['lightning']['unit'] ?? 'mi');
    }

    /**
     * @return array{min: int, max: int}
     */
    public function radiusBounds(): array
    {
        return ['min' => (int) ($this->options['lightning']['min'] ?? 1), 'max' => (int) ($this->options['lightning']['max'] ?? 50)];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    private function productPayload(array $draft): array
    {
        $mode = (string) $draft['matching_mode'];

        return [
            'product_ids' => array_values($draft['product_ids']),
            'matching_mode' => $mode,
            ...(in_array($mode, self::LOCATION_MODES, true) ? ['location_ids' => array_values($draft['location_ids'])] : []),
            ...($mode === 'wfo' ? ['wfo' => $draft['wfo']] : []),
            ...($mode === 'county_zone' ? ['county_zone_codes' => array_values($draft['county_zone_codes'])] : []),
            ...($draft['category'] === 'spc_wpc' ? ['risk_levels' => array_values($draft['risk_levels'])] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, string>  $errors
     */
    private function requireLocationAndTime(array $draft, array &$errors): void
    {
        if ($draft['location_id'] === null) {
            $errors['location_id'] = 'Choose a location.';
        }

        if ($draft['delivery_time'] === '') {
            $errors['delivery_time'] = 'Choose a delivery time.';
        }
    }
}
