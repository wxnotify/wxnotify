<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The text and slicing the Alerts screen's "current watches, warnings and advisories" section shows.
 */
class ActiveAlertsPresenter
{
    public const FAMILY_LABELS = ['warning' => 'Warning', 'watch' => 'Watch', 'advisory' => 'Advisory'];

    private const SHOWN = 5;

    /**
     * "Warning · Ends 3 hours from now", or "Watch · No end time given".
     *
     * @param  array<string, mixed>  $event
     */
    public static function overline(array $event): string
    {
        $family = self::FAMILY_LABELS[$event['family'] ?? ''] ?? ucfirst((string) ($event['family'] ?? 'Alert'));

        if (blank($event['expires'] ?? null)) {
            return "{$family} · No end time given";
        }

        return $family.' · Ends '.Carbon::parse($event['expires'])->diffForHumans();
    }

    /**
     * @param  array<string, mixed>  $event
     */
    public static function places(array $event): string
    {
        return implode(', ', $event['places'] ?? []);
    }

    /**
     * Events whose end has passed (a saved copy read later); events with no end stay.
     *
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    public static function withoutEnded(array $events): array
    {
        return array_values(array_filter($events, fn (array $event): bool => blank($event['expires'] ?? null) || Carbon::parse($event['expires'])->isFuture()));
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     * @return array<int, array<string, mixed>>
     */
    public static function visible(array $events, bool $expanded): array
    {
        return $expanded ? $events : array_slice($events, 0, self::SHOWN);
    }

    /**
     * @param  array<int, array<string, mixed>>  $events
     */
    public static function hiddenCount(array $events, bool $expanded): int
    {
        return $expanded ? 0 : max(0, count($events) - self::SHOWN);
    }

    /**
     * "As of Oct 12, 2:14 PM CDT" in the subscriber's timezone, else "As of 5 minutes ago".
     */
    public static function asOf(string $iso, ?string $timezone): string
    {
        return 'As of '.(AlertPresenter::absolute($iso, $timezone) ?? AlertPresenter::when($iso));
    }
}
