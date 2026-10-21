<?php

namespace App\Support;

/**
 * US and Canadian (NANP) phone numbers, stored in E.164 (`+1NXXNXXXXXX`).
 *
 * No other country is accepted in this batch, and no phone-number library is used:
 * a NANP number is ten digits whose area code and exchange both start 2-9.
 */
class PhoneNumber
{
    public const INVALID_MESSAGE = 'Enter a US or Canadian number, e.g. (555) 234-5678.';

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
}
