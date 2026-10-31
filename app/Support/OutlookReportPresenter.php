<?php

namespace App\Support;

use App\Enums\OutlookCategory;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Support\Carbon;

/**
 * Turns a BuildOutlookReport payload into display strings for the Full outlook report email, so the
 * mail views stay logic-free. A day that could not be fetched reads as unavailable, never as "no
 * storms", and so does a single level whose file failed ("not available"); a chosen location outside
 * every area reads as None.
 */
class OutlookReportPresenter
{
    private const UNAVAILABLE = '❔';

    private const NONE = 'none';

    private const NOT_AVAILABLE = 'not available';

    private const SEPARATOR = ' · ';

    /**
     * @param  array<string, mixed>  $payload
     * @return array{days: list<array<string, mixed>>, extended: list<array<string, mixed>>, extended_available: bool, pwo: array{title: string, text: string, issued: ?string}|null}
     */
    public static function present(array $payload): array
    {
        $days = [];
        foreach ([1, 2, 3] as $number) {
            $days[] = self::convectiveDay($number, $payload['days'][$number] ?? null, (bool) ($payload['day1_missing'] ?? false));
        }

        $extended = [];
        foreach ([4, 5, 6, 7, 8] as $number) {
            $extended[] = self::extendedDay($number, $payload['extended'][$number] ?? null);
        }

        $pwo = $payload['pwo'] ?? null;

        return [
            'days' => $days,
            'extended' => $extended,
            'extended_available' => in_array(true, array_column($extended, 'available'), true),
            'pwo' => is_array($pwo) ? [
                'title' => (string) $pwo['title'],
                // AWIPS bulletins end lines in \r\r\n; normalised in the same order as ChannelMessageMail.
                'text' => str_replace(["\r\r\n", "\r\n", "\r"], "\n", (string) $pwo['text']),
                'issued' => self::issued($pwo['issued_at'] ?? null),
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $view  the output of present()
     */
    public static function text(array $view): string
    {
        $lines = [];

        foreach ($view['days'] as $day) {
            $lines[] = '';
            $lines[] = strtoupper($day['heading']).': '.$day['circle'].($day['available'] ? ' '.$day['label'] : '');

            if (! $day['available']) {
                $lines[] = $day['unavailable'];

                continue;
            }

            $lines[] = $day['levels'];

            if ($day['issued'] !== null) {
                $lines[] = $day['issued'];
            }

            foreach ($day['locations'] as $location) {
                $levels = $location['levels'] !== null ? ' — '.$location['levels'] : '';
                $lines[] = "{$location['label']}: {$location['circle']} {$location['category']}{$levels}";
            }
        }

        $lines[] = '';
        $lines[] = 'DAYS 4-8';

        foreach ($view['extended'] as $day) {
            $inside = $day['inside'] !== null ? ' — '.$day['inside'] : '';
            $lines[] = "{$day['heading']}: {$day['circle']} {$day['summary']}{$inside}";
        }

        if ($view['pwo'] !== null) {
            $lines[] = '';
            $lines[] = strtoupper($view['pwo']['title']);

            if ($view['pwo']['issued'] !== null) {
                $lines[] = $view['pwo']['issued'];
            }

            $lines[] = $view['pwo']['text'];
        }

        return trim(implode("\n", $lines));
    }

    /**
     * @param  array<string, mixed>|null  $day
     * @return array<string, mixed>
     */
    private static function convectiveDay(int $number, ?array $day, bool $day1Missing): array
    {
        $available = $day !== null && ($day['available'] ?? false) === true;
        $category = $available ? OutlookCategory::tryFrom((string) $day['category']) ?? OutlookCategory::None : null;

        return [
            'number' => $number,
            'key' => "day{$number}",
            'heading' => "Day {$number}",
            'available' => $available,
            'circle' => $category?->emoji() ?? self::UNAVAILABLE,
            'label' => $category?->label(),
            'unavailable' => $number === 1 && $day1Missing ? 'Day 1 outlook not yet available' : "Day {$number} outlook not available",
            'alt' => "SPC Day {$number} convective outlook",
            'levels' => $available ? self::dayLevels($number, $day, self::missing($day)) : null,
            'issued' => $available ? self::issued($day['issued_at'] ?? null) : null,
            'locations' => $available ? array_map(fn (array $row): array => self::locationRow($number, $row, self::missing($day)), $day['locations'] ?? []) : [],
        ];
    }

    /**
     * The levels whose SPC file failed (see BuildOutlookReport): they read "not available", never none.
     *
     * @param  array<string, mixed>  $day
     * @return list<string>
     */
    private static function missing(array $day): array
    {
        return is_array($day['missing'] ?? null) ? array_values(array_filter($day['missing'], is_string(...))) : [];
    }

    /**
     * @param  array<string, mixed>  $day
     * @param  list<string>  $missing
     */
    private static function dayLevels(int $number, array $day, array $missing): string
    {
        if ($number === 3) {
            return 'Any severe '.self::level($day['any_severe'] ?? null, in_array('any_severe', $missing, true));
        }

        return self::hazards($day['hail'] ?? null, $day['wind'] ?? null, $day['tornado'] ?? null, $missing);
    }

    /**
     * Day 3 has no per-hazard levels, and a location in no hazard area needs no levels line.
     *
     * @param  array<string, mixed>  $row
     * @param  list<string>  $missing
     * @return array{label: string, circle: string, category: string, levels: ?string}
     */
    private static function locationRow(int $number, array $row, array $missing): array
    {
        $category = OutlookCategory::tryFrom((string) ($row['category'] ?? '')) ?? OutlookCategory::None;
        $hail = $row['hail'] ?? null;
        $wind = $row['wind'] ?? null;
        $tornado = $row['tornado'] ?? null;

        return [
            'label' => (string) $row['label'],
            'circle' => $category->emoji(),
            'category' => $category->label(),
            'levels' => $number === 3 || ($hail === null && $wind === null && $tornado === null && $missing === []) ? null : self::hazards($hail, $wind, $tornado, $missing),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $day
     * @return array{heading: string, available: bool, circle: string, summary: string, highlight: bool, inside: ?string}
     */
    private static function extendedDay(int $number, ?array $day): array
    {
        $available = $day !== null && ($day['available'] ?? false) === true;
        $highlight = $available && ($day['highlight'] ?? false) === true;
        $locations = $highlight ? array_map(strval(...), $day['locations'] ?? []) : [];

        return [
            'heading' => "Day {$number}",
            'available' => $available,
            'circle' => $available ? (OutlookCategory::tryFrom((string) $day['category']) ?? OutlookCategory::None)->emoji() : self::UNAVAILABLE,
            'summary' => match (true) {
                ! $available => 'Not available',
                $highlight => (string) $day['probability'],
                default => 'Below 15%',
            },
            'highlight' => $highlight,
            'inside' => match (true) {
                ! $highlight => null,
                $locations === [] => 'None of your locations inside',
                default => 'Inside: '.implode(', ', $locations),
            },
        ];
    }

    /**
     * @param  list<string>  $missing
     */
    private static function hazards(mixed $hail, mixed $wind, mixed $tornado, array $missing): string
    {
        return implode(self::SEPARATOR, [
            'Hail '.self::level($hail, in_array('hail', $missing, true)),
            'Wind '.self::level($wind, in_array('wind', $missing, true)),
            'Tornado '.self::level($tornado, in_array('tornado', $missing, true)),
        ]);
    }

    private static function level(mixed $value, bool $isMissing = false): string
    {
        if ($isMissing) {
            return self::NOT_AVAILABLE;
        }

        return is_string($value) && $value !== '' ? $value : self::NONE;
    }

    private static function issued(mixed $iso): ?string
    {
        if (! is_string($iso) || $iso === '') {
            return null;
        }

        try {
            return 'Issued '.Carbon::parse($iso)->utc()->format('D, M j H:i').' UTC';
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
