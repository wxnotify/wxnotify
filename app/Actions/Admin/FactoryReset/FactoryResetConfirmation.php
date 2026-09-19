<?php

namespace App\Actions\Admin\FactoryReset;

class FactoryResetConfirmation
{
    /**
     * @param  array<string, mixed>  $toggles
     */
    public static function expectedPhrase(array $toggles): string
    {
        if ($toggles['subscribers'] ?? false) {
            return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
        }

        return 'RESET';
    }

    /**
     * @param  array<string, mixed>  $toggles
     */
    public static function matches(array $toggles, string $typed): bool
    {
        $expected = self::expectedPhrase($toggles);
        $typed = trim($typed);

        return ($toggles['subscribers'] ?? false)
            ? $expected !== '' && strcasecmp($typed, $expected) === 0
            : $typed === $expected;
    }
}
