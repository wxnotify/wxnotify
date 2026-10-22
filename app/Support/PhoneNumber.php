<?php

namespace App\Support;

/**
 * Phone numbers, stored in E.164. The SMS types take only US and Canadian (NANP) numbers
 * (`+1NXXNXXXXXX`); WhatsApp also takes international numbers. No phone-number library is
 * used: a NANP number is ten digits whose area code and exchange both start 2-9, and an
 * international number is checked for shape and length only, not for its numbering plan.
 */
class PhoneNumber
{
    public const INVALID_MESSAGE = 'Enter a US or Canadian number, e.g. (555) 234-5678.';

    public const INTERNATIONAL_INVALID_MESSAGE = 'Enter your WhatsApp number with its country code, e.g. +52 55 1234 5678 or (555) 234-5678.';

    /**
     * The ITU-T E.164 country codes that are two digits long. `1` (NANP) and `7` are the only
     * one-digit codes; every other code is three digits.
     */
    private const TWO_DIGIT_COUNTRY_CODES = [
        '20', '27', '30', '31', '32', '33', '34', '36', '39', '40', '41', '43', '44', '45', '46', '47',
        '48', '49', '51', '52', '53', '54', '55', '56', '57', '58', '60', '61', '62', '63', '64', '65',
        '66', '81', '82', '84', '86', '90', '91', '92', '93', '94', '95', '98',
    ];

    /**
     * Ten digits, 1 + ten digits, or +1 + ten digits, with any spaces, dots, dashes or
     * parentheses, as `+1NXXNXXXXXX`; null for anything else (letters, extensions,
     * other country codes, a 0/1 area code or exchange).
     */
    public static function normalize(string $input): ?string
    {
        $compact = preg_replace('/[\s.\-()]/', '', $input);

        if (! preg_match('/^(\+1|1)?([2-9]\d{2}[2-9]\d{6})$/', (string) $compact, $matches)) {
            return null;
        }

        return '+1'.$matches[2];
    }

    /**
     * Everything normalize() accepts, with the same result, and also `+` or `00` followed by
     * 8-15 digits whose country code does not start 0 or 1, with the same formatting allowed.
     * A `+1` (or `001`) number must pass the NANP rules. A parenthesised zero anywhere (the trunk
     * convention `+44 (0)7700 900123`, or a typo) is refused rather than stripped to a wrong
     * number; a plain leading 0 after the country code stays (`+39 06…` is a real Italian number). Null for anything else.
     */
    public static function normalizeInternational(string $input): ?string
    {
        $nanp = self::normalize($input);

        if ($nanp !== null) {
            return $nanp;
        }

        if (preg_match('/\(\s*0\s*\)/', $input)) {
            return null;
        }

        $compact = (string) preg_replace('/[\s.\-()]/', '', $input);

        if (str_starts_with($compact, '00')) {
            $compact = '+'.substr($compact, 2);
        }

        if (str_starts_with($compact, '+1')) {
            return self::normalize($compact);
        }

        return preg_match('/^\+[2-9]\d{7,14}$/', $compact) ? $compact : null;
    }

    /**
     * The E.164 number without its plus (`1NXXNXXXXXX`), the form Apprise takes.
     */
    public static function digits(string $e164): string
    {
        return ltrim($e164, '+');
    }

    /**
     * The last four digits behind a mask (`•••• 4567`), enough to tell numbers apart.
     */
    public static function mask(string $e164): string
    {
        return '•••• '.mb_substr($e164, -4);
    }

    /**
     * The country code and the last four digits (`+52 •••• 5678`), so a number read in the wrong
     * country (a bare ten-digit number is taken as NANP) shows it; mask() for anything not E.164.
     */
    public static function maskWithCountryCode(string $e164): string
    {
        if (preg_match('/^\+[1-9]\d{7,14}$/D', $e164) !== 1) {
            return self::mask($e164);
        }

        $digits = self::digits($e164);
        $length = match (true) {
            in_array($digits[0], ['1', '7'], true) => 1,
            in_array(substr($digits, 0, 2), self::TWO_DIGIT_COUNTRY_CODES, true) => 2,
            default => 3,
        };

        return '+'.substr($digits, 0, $length).' '.self::mask($e164);
    }
}
