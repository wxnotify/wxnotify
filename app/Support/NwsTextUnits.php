<?php

namespace App\Support;

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
    private const KMH_PER_MPH = 1.609344;

    private const CM_PER_INCH = 2.54;

    /**
     * A temperature number that is not already converted ("26°C"), a decimal,
     * or a clock time ("5pm").
     */
    private const TEMP = '(-?\d++)(?!°|\.\d|\s?[ap]m\b)';

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
            '/\b(\d+) mph\b/i',
            fn (array $m): string => self::kmh($m[1]).' km/h',
            $converted,
        );
        $converted = preg_replace_callback(
            '/\b(\d+(?:\.\d+)?) to (\d+(?:\.\d+)?) inch(?:es)?\b/i',
            fn (array $m): string => self::cm($m[1]).' to '.self::cm($m[2]).' cm',
            $converted,
        );
        $converted = preg_replace_callback(
            '/\b(\d+(?:\.\d+)?) inch(?:es)?\b/i',
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
        return (string) (int) round((float) $mph * self::KMH_PER_MPH);
    }

    private static function celsius(string $fahrenheit): string
    {
        return (string) (int) round(((float) $fahrenheit - 32) * 5 / 9);
    }

    private static function cm(string $inches): string
    {
        return Units::number((float) $inches * self::CM_PER_INCH, 1);
    }
}
