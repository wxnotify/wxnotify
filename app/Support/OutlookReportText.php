<?php

namespace App\Support;

use App\Enums\OutlookCategory;

/**
 * The one-line texts of an outlook report payload (see BuildOutlookReport): the subject and the Brief.
 */
class OutlookReportText
{
    private const UNAVAILABLE = '❔';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function subject(array $payload): string
    {
        $parts = [];

        foreach ([1, 2, 3] as $day) {
            $parts[] = self::emoji($payload['days'][$day]['category'] ?? null)." D{$day}";
        }

        foreach ([4, 5, 6, 7, 8] as $day) {
            $parts[] = self::emoji($payload['extended'][$day]['category'] ?? null)." D{$day}";
        }

        return 'Severe Wx Outlook - '.implode(' | ', $parts);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function brief(array $payload): string
    {
        $parts = [];

        foreach ([1, 2, 3] as $day) {
            $parts[] = "D{$day} ".self::emoji($payload['days'][$day]['category'] ?? null);
        }

        $highest = null;

        foreach ([4, 5, 6, 7, 8] as $day) {
            $category = OutlookCategory::tryFrom((string) ($payload['extended'][$day]['category'] ?? ''));

            if ($category !== null && ($highest === null || $category->rank() > $highest->rank())) {
                $highest = $category;
            }
        }

        $parts[] = 'D4-8 '.($highest?->emoji() ?? self::UNAVAILABLE);

        return implode('  ', $parts);
    }

    private static function emoji(?string $category): string
    {
        return OutlookCategory::tryFrom((string) $category)?->emoji() ?? self::UNAVAILABLE;
    }
}
