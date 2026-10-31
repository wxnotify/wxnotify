<?php

namespace App\Support;

use App\Models\AlertRule;
use App\Models\Location;
use App\Models\User;
use DateTimeZone;
use IntlTimeZone;

/**
 * The one place that decides what a stored timezone means. A Location's own
 * timezone always wins; a subscriber's profile timezone (users.timezone) is
 * only a fallback for a Location that has none (mobile locations) and for the
 * dashboard charts. A stored profile value counts only if it is a canonical
 * IANA identifier (the same list the timezone select offers); anything else is
 * treated as unset, so a subscriber with no profile timezone resolves exactly
 * as the app did before the setting existed.
 */
final class Timezones
{
    public static function isValid(?string $timezone): bool
    {
        return filled($timezone) && in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Returns a valid canonical identifier for the given timezone, or null.
     * Browsers (Chromium/ICU) can report legacy aliases such as
     * America/Indianapolis or Asia/Calcutta that the timezone select never
     * offers; ICU's canonical IANA id is used when this build supports it.
     */
    public static function normalise(?string $timezone): ?string
    {
        if (blank($timezone)) {
            return null;
        }

        if (self::isValid($timezone)) {
            return $timezone;
        }

        $canonical = method_exists(IntlTimeZone::class, 'getIanaID') ? IntlTimeZone::getIanaID($timezone) : false;

        return is_string($canonical) && self::isValid($canonical) ? $canonical : null;
    }

    public static function forUser(?User $user): ?string
    {
        $timezone = $user?->timezone;

        return self::isValid($timezone) ? $timezone : null;
    }

    public static function forLocation(Location $location): ?string
    {
        return filled($location->timezone) ? $location->timezone : self::forUser($location->user);
    }

    public static function forRule(AlertRule $rule): string
    {
        $locationTimezone = $rule->location?->timezone;

        return filled($locationTimezone) ? $locationTimezone : self::forUserOrDefault($rule->user);
    }

    /**
     * The timezone a rule with no Location (the outlook report) runs in for this owner: the profile
     * timezone, else the app timezone (UTC), exactly as forRule() resolves such a rule.
     */
    public static function forUserOrDefault(?User $user): string
    {
        return self::forUser($user) ?? config('app.timezone');
    }
}
