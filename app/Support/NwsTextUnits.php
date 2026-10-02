<?php

namespace App\Support;

use App\Enums\UnitSystem;

/**
 * Rewrites NWS's standardized forecast phrases from imperial to Metric for a
 * Metric subscriber. NWS writes its detailed forecasts and hourly wind text in
 * mph / degF (no unit symbol) / inches, so this converts only the phrase shapes
 * NWS actually uses, sentence by sentence. A temperature is converted only after
 * a known lead-in ("high near", "falling to around", "heat index values as high
 * as") — never a bare number — so clock times ("before 5pm") and percentages
 * ("40%") can't be touched. If any unit token (mph, inch, degrees, degF) is still
 * present after converting a sentence, that sentence reverts to NWS's original
 * wording: it is never half-converted.
 */
class NwsTextUnits
{
    /**
     * A temperature number (digits or the word "zero") that is not already
     * converted ("26°C"), a decimal, or a clock time ("5pm"). The digits are
     * possessive (\d++) on purpose: without it the lookahead lets them
     * backtrack ("18°C" matches as "1" followed by "8°C"), so a second pass
     * re-converts an already-converted value into garbage.
     */
    private const TEMP = '(-?\d++|zero\b)(?!°|\.\d|\s?[ap]m\b)';

    /**
     * A number that is the second half of a range ("1 and 2 mph", "1 to 2
     * inches") is never converted on its own, so a range the patterns below do
     * not recognise keeps its unit token and the whole sentence reverts.
     */
    private const NOT_RANGE_END = '(?<!\d and )(?<!\d to )';

    public static function toMetric(string $text): string
    {
        $parts = preg_split('/((?<=[.!?])\s+(?=[A-Z]))/', $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($parts === false) {
            return $text;
        }

        foreach ($parts as $index => $part) {
            if ($index % 2 === 0) {
                $parts[$index] = self::convertSentence($part);
            }
        }

        return implode('', $parts);
    }

    private static function convertSentence(string $sentence): string
    {
        $converted = $sentence;

        $converted = preg_replace_callback(
            '/\b(\d+) to (\d+) mph\b/i',
            fn (array $m): string => self::kmh($m[1]).' to '.self::kmh($m[2]).' km/h',
            $converted,
        );
        $converted = preg_replace_callback(
            '/'.self::NOT_RANGE_END.'\b(\d+) mph\b/i',
            fn (array $m): string => self::kmh($m[1]).' km/h',
            $converted,
        );
        $converted = preg_replace_callback(
            '/\b(\d+(?:\.\d+)?) to (\d+(?:\.\d+)?) inch(?:es)?\b/i',
            fn (array $m): string => self::cm($m[1]).' to '.self::cm($m[2]).' cm',
            $converted,
        );
        $converted = preg_replace_callback(
            '/\bbetween (\d+(?:\.\d+)?) and (\d+(?:\.\d+)?) inch(?:es)?\b/i',
            fn (array $m): string => 'between '.self::cm($m[1]).' and '.self::cm($m[2]).' cm',
            $converted,
        );
        $converted = preg_replace_callback(
            '/'.self::NOT_RANGE_END.'\b(\d+(?:\.\d+)?) inch(?:es)?\b/i',
            fn (array $m): string => self::cm($m[1]).' cm',
            $converted,
        );

        $converted = preg_replace_callback(
            '/\b(high|low) (near|around) '.self::TEMP.'/i',
            fn (array $m): string => "{$m[1]} {$m[2]} ".self::celsius($m[3]).'°C',
            $converted,
        );
        $converted = preg_replace_callback(
            '/\b(falling|rising) to (near|around) '.self::TEMP.'/i',
            fn (array $m): string => "{$m[1]} to {$m[2]} ".self::celsius($m[3]).'°C',
            $converted,
        );
        $converted = preg_replace_callback(
            '/\b(temperatures?) (near|around) '.self::TEMP.'/i',
            fn (array $m): string => "{$m[1]} {$m[2]} ".self::celsius($m[3]).'°C',
            $converted,
        );
        $converted = preg_replace_callback(
            '/\b(heat index|wind chill) values as (high|low) as '.self::TEMP.'/i',
            fn (array $m): string => "{$m[1]} values as {$m[2]} as ".self::celsius($m[3]).'°C',
            $converted,
        );

        if (preg_match('/\b(?:mph|inch(?:es)?|degrees?)\b|°F/i', $converted) === 1) {
            return $sentence;
        }

        return $converted;
    }

    private static function kmh(string $mph): string
    {
        return (string) (int) round(self::metric()->speedValue((float) $mph));
    }

    private static function celsius(string $fahrenheit): string
    {
        $value = strtolower($fahrenheit) === 'zero' ? 0.0 : (float) $fahrenheit;

        return (string) (int) round(self::metric()->temperatureValue($value));
    }

    private static function cm(string $inches): string
    {
        return Units::number(self::metric()->depthValue((float) $inches), 1);
    }

    private static function metric(): Units
    {
        return new Units(UnitSystem::Metric);
    }
}
