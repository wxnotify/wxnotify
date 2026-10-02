<?php

namespace App\Support;

use App\Enums\ThresholdVariable;
use App\Models\AlertRule;

/**
 * Converts the alert form's two unit-bearing fields between what a Metric
 * subscriber sees and what is stored (imperial). Imperial subscribers pass
 * through untouched.
 *
 * toStorage() carries the no-drift guard: a value the subscriber did not change
 * (the submitted number equals the display of the stored one) is replaced by the
 * stored value itself, so a stored 35.0 degF -- shown as 1.7 degC -- never
 * becomes 35.06 -> 35.1 just because the form was saved.
 */
class AlertRuleFormUnits
{
    /**
     * @param  array<string, mixed>  $data  stored (imperial) values
     * @return array<string, mixed>
     */
    public static function toDisplay(array $data, Units $units): array
    {
        if (! $units->isMetric()) {
            return $data;
        }

        $variable = ThresholdVariable::tryFrom((string) ($data['variable'] ?? ''));

        if ($variable !== null && is_numeric($data['threshold_value'] ?? null)) {
            $data['threshold_value'] = round($variable->displayValue((float) $data['threshold_value'], $units->system), 1);
        }

        if (is_numeric($data['radius_miles'] ?? null)) {
            $data['radius_miles'] = (int) round($units->distanceValue((float) $data['radius_miles']));
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data  form values in the subscriber's unit
     * @return array<string, mixed>
     */
    public static function toStorage(array $data, Units $units, ?AlertRule $stored = null): array
    {
        if (! $units->isMetric()) {
            return $data;
        }

        $variable = ThresholdVariable::tryFrom((string) ($data['variable'] ?? ''));

        if ($variable !== null && is_numeric($data['threshold_value'] ?? null)) {
            $submitted = (float) $data['threshold_value'];
            $unchanged = $stored !== null
                && $stored->variable === $variable
                && is_numeric($stored->threshold_value)
                && abs($submitted - round($variable->displayValue((float) $stored->threshold_value, $units->system), 1)) < 0.0001;

            $data['threshold_value'] = $unchanged
                ? (float) $stored->threshold_value
                : round($variable->canonicalValue($submitted, $units->system), 1);
        }

        if (is_numeric($data['radius_miles'] ?? null)) {
            $submitted = (int) round((float) $data['radius_miles']);
            $unchanged = $stored !== null
                && $stored->radius_miles !== null
                && $submitted === (int) round($units->distanceValue((float) $stored->radius_miles));

            $data['radius_miles'] = $unchanged ? (int) $stored->radius_miles : (int) round($units->kilometersToMiles($submitted));
        }

        return $data;
    }
}
