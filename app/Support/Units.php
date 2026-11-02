<?php

namespace App\Support;

use App\Enums\UnitSystem;
use App\Models\AlertRule;
use App\Models\User;

/**
 * The one place that knows how to convert, format and label a measurement.
 * Everything in the app is stored and computed in imperial units (degF, mph,
 * inHg, mi, ft, in); a subscriber's Metric preference is applied only at the
 * display edge (and inverted for form input), so switching systems never
 * changes what a stored alert means. Imperial methods are identity
 * conversions that format exactly as the app always has.
 */
final class Units
{
    private const KM_PER_MILE = 1.609344;

    private const HPA_PER_INHG = 33.8639;

    private const METERS_PER_FOOT = 0.3048;

    private const CM_PER_INCH = 2.54;

    public function __construct(public readonly UnitSystem $system = UnitSystem::Imperial) {}

    public static function for(?User $user): self
    {
        return new self($user?->unit_system ?? UnitSystem::Imperial);
    }

    public static function forRule(AlertRule $rule): self
    {
        return self::for($rule->user);
    }

    /**
     * @param  array<string, mixed>  $report  a BuildDailySummaryReport array; reports queued before this
     *                                        feature carry no `units` key and render Imperial
     */
    public static function fromReport(array $report): self
    {
        return new self(UnitSystem::tryFrom((string) ($report['units'] ?? '')) ?? UnitSystem::Imperial);
    }

    public function isMetric(): bool
    {
        return $this->system === UnitSystem::Metric;
    }

    public function temperatureValue(float $fahrenheit): float
    {
        return $this->isMetric() ? ($fahrenheit - 32) * 5 / 9 : $fahrenheit;
    }

    public function speedValue(float $mph): float
    {
        return $this->isMetric() ? $mph * self::KM_PER_MILE : $mph;
    }

    public function distanceValue(float $miles): float
    {
        return $this->isMetric() ? $miles * self::KM_PER_MILE : $miles;
    }

    public function elevationValue(float $feet): float
    {
        return $this->isMetric() ? $feet * self::METERS_PER_FOOT : $feet;
    }

    public function depthValue(float $inches): float
    {
        return $this->isMetric() ? $inches * self::CM_PER_INCH : $inches;
    }

    public function pressureValue(float $inHg): float
    {
        return $this->isMetric() ? $inHg * self::HPA_PER_INHG : $inHg;
    }

    public function temperatureToFahrenheit(float $value): float
    {
        return $this->isMetric() ? $value * 9 / 5 + 32 : $value;
    }

    public function speedToMph(float $value): float
    {
        return $this->isMetric() ? $value / self::KM_PER_MILE : $value;
    }

    /**
     * Metric-source conversions (WeatherKit sends Celsius, km/h, hPa and km) into the imperial values the rest
     * of this class formats; independent of the subscriber's system.
     */
    public static function fahrenheitFromCelsius(float $celsius): float
    {
        return $celsius * 9 / 5 + 32;
    }

    public static function mphFromKilometersPerHour(float $kph): float
    {
        return $kph / self::KM_PER_MILE;
    }

    public static function inHgFromHectopascals(float $hectopascals): float
    {
        return $hectopascals / self::HPA_PER_INHG;
    }

    public static function milesFromKilometers(float $kilometers): float
    {
        return $kilometers / self::KM_PER_MILE;
    }

    public function kilometersToMiles(float $value): float
    {
        return $this->isMetric() ? $value / self::KM_PER_MILE : $value;
    }

    public function temperatureUnit(): string
    {
        return $this->isMetric() ? '°C' : '°F';
    }

    public function speedUnit(): string
    {
        return $this->isMetric() ? 'km/h' : 'mph';
    }

    public function pressureUnit(): string
    {
        return $this->isMetric() ? 'hPa' : 'inHg';
    }

    public function distanceUnit(): string
    {
        return $this->isMetric() ? 'km' : 'mi';
    }

    public function distanceUnitName(): string
    {
        return $this->isMetric() ? 'km' : 'miles';
    }

    public function elevationUnit(): string
    {
        return $this->isMetric() ? 'm' : 'ft';
    }

    public function depthUnit(): string
    {
        return $this->isMetric() ? 'cm' : 'in';
    }

    public function temperature(float $fahrenheit, int $decimals = 0): string
    {
        return self::number($this->temperatureValue($fahrenheit), $decimals).$this->temperatureUnit();
    }

    public function speed(float $mph, int $decimals = 0): string
    {
        return self::number($this->speedValue($mph), $decimals).' '.$this->speedUnit();
    }

    public function pressure(float $inHg): string
    {
        return $this->isMetric()
            ? number_format($this->pressureValue($inHg), 0, '.', '').' hPa'
            : number_format($inHg, 2).' inHg';
    }

    public function distance(float $miles, int $decimals = 1): string
    {
        return self::number($this->distanceValue($miles), $decimals).' '.$this->distanceUnit();
    }

    public function elevation(float $feet): string
    {
        return number_format($this->elevationValue($feet)).' '.$this->elevationUnit();
    }

    public function depth(float $inches, int $decimals = 1): string
    {
        return self::number($this->depthValue($inches), $decimals).' '.$this->depthUnit();
    }

    /**
     * The smallest and largest whole display value that still converts to a
     * stored mile count inside the allowed range: rounding up for the minimum
     * and down for the maximum, so anything the form accepts is valid.
     */
    public function minDistanceDisplay(int $miles): int
    {
        return $this->isMetric() ? (int) ceil($miles * self::KM_PER_MILE) : $miles;
    }

    public function maxDistanceDisplay(int $miles): int
    {
        return $this->isMetric() ? (int) floor($miles * self::KM_PER_MILE) : $miles;
    }

    /**
     * Rounds to $decimals, trims trailing zeros ("10.0" -> "10") and never
     * prints a negative zero.
     */
    public static function number(float $value, int $decimals = 0): string
    {
        $text = number_format(round($value, $decimals), $decimals, '.', '');

        if ($decimals > 0) {
            $text = rtrim(rtrim($text, '0'), '.');
        }

        return $text === '-0' ? '0' : $text;
    }
}
