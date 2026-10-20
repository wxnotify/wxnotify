<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Turns API alert payloads into the text the screens show.
 */
class AlertPresenter
{
    public static function when(string $iso): string
    {
        return Carbon::parse($iso)->diffForHumans();
    }

    /**
     * An absolute time, only when the subscriber has a timezone: the app cannot assume the device's.
     */
    public static function absolute(string $iso, ?string $timezone): ?string
    {
        return $timezone === null ? null : Carbon::parse($iso)->setTimezone($timezone)->format('M j, g:i A T');
    }

    /**
     * @param  array<string, mixed>  $alert
     */
    public static function subtitle(array $alert): string
    {
        return self::withCategory($alert, self::when($alert['dispatched_at']));
    }

    /**
     * "Category · time", or just the time for legacy deliveries that have no category.
     *
     * @param  array<string, mixed>  $alert
     */
    public static function withCategory(array $alert, string $time): string
    {
        $category = trim((string) ($alert['category_label'] ?? ''));

        return $category === '' ? $time : $category.' · '.$time;
    }
}
