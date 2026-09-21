<?php

namespace App\Support;

use App\Enums\Secret;
use App\Settings\SecretSettings;
use Throwable;

/**
 * Reads WxNotify's own production credentials: the value stored (encrypted)
 * in App settings wins, the .env-backed config is the fallback. Never throws;
 * if the settings cannot be read the .env value is used.
 *
 * A decryption failure (for example a wrong or rotated APP_KEY) is treated
 * like "cannot be read": isStored() is false and get() falls back to the .env
 * value, with no signal. Never log the exception message or any value.
 */
class Secrets
{
    public static function get(Secret $secret): ?string
    {
        $stored = self::stored($secret);

        if (filled($stored)) {
            return $stored;
        }

        $fallback = config($secret->configKey());

        return is_string($fallback) && filled($fallback) ? $fallback : null;
    }

    public static function isStored(Secret $secret): bool
    {
        return filled(self::stored($secret));
    }

    private static function stored(Secret $secret): ?string
    {
        try {
            return app(SecretSettings::class)->{$secret->settingsProperty()};
        } catch (Throwable) {
            return null;
        }
    }
}
