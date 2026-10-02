<?php

namespace App\Support;

use App\Settings\GeneralSettings;
use Throwable;

/**
 * Prepends the admin-configured prefix (App Settings → General → Message
 * prefix) to subscriber- and staff-facing message titles/subjects. Fails
 * safe like ApplyEmailSettings: a message must still go out, unprefixed,
 * if settings can't be read.
 */
class MessagePrefix
{
    public static function apply(string $text): string
    {
        try {
            $settings = app(GeneralSettings::class);
        } catch (Throwable) {
            return $text;
        }

        if (! $settings->message_prefix_enabled || blank($settings->message_prefix)) {
            return $text;
        }

        return trim($settings->message_prefix).' '.$text;
    }
}
