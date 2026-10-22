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

    /**
     * The text without the prefix apply() put in front of it, for a channel where the prefix
     * makes no sense (a voice call reads the title out). Only a leading configured prefix is
     * removed; fails safe by returning the text unchanged.
     */
    public static function strip(string $text): string
    {
        try {
            $settings = app(GeneralSettings::class);
        } catch (Throwable) {
            return $text;
        }

        $prefix = trim((string) $settings->message_prefix);

        if (! $settings->message_prefix_enabled || $prefix === '' || ! str_starts_with($text, $prefix.' ')) {
            return $text;
        }

        return substr($text, strlen($prefix) + 1);
    }
}
