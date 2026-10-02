<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Turns a BuildDailySummaryReport array into display strings. All formatting
 * lives here so the mail views stay logic-free and everything is unit-testable
 * without rendering mail. ISO timestamps keep their embedded offset when
 * parsed, so every time shown is in the location's timezone.
 */
class DailySummaryPresenter
{
    private const DASH = '—';

    /**
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    public static function present(array $report): array
    {
        $current = $report['current'] ?? null;
        $radarSite = $report['radar_site'] ?? null;
        $timezone = $report['timezone'] ?? config('app.timezone');

        return [
            'location' => (string) ($report['location'] ?? ''),
            'station_line' => $current !== null ? self::stationLine($current['station']) : null,
            'sun_line' => self::sunLine($report['sun'] ?? null),
            'alerts' => isset($report['alerts']) ? array_map(fn (array $alert): array => [
                'event' => (string) $alert['event'],
                'headline' => $alert['headline'] ?? null,
                'until' => isset($alert['expires']) ? 'until '.Carbon::parse($alert['expires'])->format('D g:i A') : null,
            ], $report['alerts']) : null,
            'current' => $current !== null ? self::currentSection($current) : null,
            'radar_alt' => is_string($radarSite) ? "Radar near {$report['location']} from {$radarSite}" : null,
            'radar_caption' => is_string($radarSite) ? 'Radar as of '.Carbon::now($timezone)->format('D, M j \a\t g:i A') : null,
            'hourly' => isset($report['hourly']) ? array_map(fn (array $hour): array => [
                'time' => Carbon::parse($hour['time'])->format('g A'),
                'icon_url' => WeatherIcon::url($hour['icon']['period'], $hour['icon']['code']),
                'temp' => self::temperature($hour['temperature'], $hour['unit']),
                'precip' => isset($hour['precip_chance']) ? $hour['precip_chance'].'%' : self::DASH,
                'wind' => filled($hour['wind']) ? $hour['wind'] : self::DASH,
                'summary' => (string) $hour['summary'],
            ], $report['hourly']) : null,
            'periods' => array_map(fn (array $period): array => [
                'icon_url' => WeatherIcon::url($period['icon']['period'], $period['icon']['code']),
                'name' => (string) $period['name'],
                'date' => isset($period['date']) ? Carbon::parse($period['date'])->format('D, M j') : null,
                'summary' => (string) $period['summary'],
                'temp' => $period['label'].' '.self::temperature($period['temperature'], $period['unit']),
            ], $report['periods'] ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $view  the output of present()
     */
    public static function text(array $view, bool $hasRadar): string
    {
        $lines = [$view['location']];

        foreach (['station_line', 'sun_line'] as $key) {
            if ($view[$key] !== null) {
                $lines[] = $view[$key];
            }
        }

        $lines[] = '';
        $lines[] = 'ALERTS IN EFFECT';

        if ($view['alerts'] === null) {
            $lines[] = 'Alert information is unavailable right now.';
        } elseif ($view['alerts'] === []) {
            $lines[] = 'No active alerts.';
        }

        foreach ($view['alerts'] ?? [] as $alert) {
            $detail = $alert['headline'] !== null ? " — {$alert['headline']}" : '';
            $until = $alert['until'] !== null ? " ({$alert['until']})" : '';
            $lines[] = "{$alert['event']}{$detail}{$until}";
        }

        if ($view['current'] !== null) {
            $lines[] = '';
            $lines[] = 'CURRENT CONDITIONS';

            if ($view['current']['description'] !== null) {
                $lines[] = $view['current']['description'];
            }

            foreach ($view['current']['rows'] as [$label, $value]) {
                $lines[] = "{$label}: {$value}";
            }

            $lines[] = $view['current']['updated'];
        }

        if ($hasRadar && $view['radar_caption'] !== null) {
            $lines[] = '';
            $lines[] = 'RADAR';
            $lines[] = $view['radar_caption'];
        }

        if ($view['hourly'] !== null) {
            $lines[] = '';
            $lines[] = 'NEXT 12 HOURS';

            foreach ($view['hourly'] as $hour) {
                $lines[] = "{$hour['time']} — {$hour['temp']} — {$hour['precip']} — {$hour['wind']} — {$hour['summary']}";
            }
        }

        $lines[] = '';
        $lines[] = '7-DAY FORECAST';

        foreach ($view['periods'] as $period) {
            $name = $period['date'] !== null ? "{$period['name']} ({$period['date']})" : $period['name'];
            $lines[] = "{$name}: {$period['summary']} — {$period['temp']}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $station
     */
    private static function stationLine(array $station): string
    {
        $line = 'Reporting station: '.(filled($station['name']) ? "{$station['name']} ({$station['id']})" : $station['id']);

        if ($station['lat'] !== null && $station['lng'] !== null) {
            $line .= ' — '.number_format($station['lat'], 4, '.', '').', '.number_format($station['lng'], 4, '.', '');
        }

        if ($station['elevation_ft'] !== null) {
            $line .= ' — elev. '.number_format($station['elevation_ft']).' ft';
        }

        return $line;
    }

    /**
     * @param  array{sunrise: string, sunset: string}|null  $sun
     */
    private static function sunLine(?array $sun): ?string
    {
        return $sun === null ? null : 'Sunrise '.Carbon::parse($sun['sunrise'])->format('g:i A').' · Sunset '.Carbon::parse($sun['sunset'])->format('g:i A');
    }

    /**
     * @param  array<string, mixed>  $current
     * @return array<string, mixed>
     */
    private static function currentSection(array $current): array
    {
        return [
            'icon_url' => WeatherIcon::url($current['icon']['period'], $current['icon']['code']),
            'description' => filled($current['description'] ?? null) ? (string) $current['description'] : null,
            'rows' => [
                ['Temperature', self::degrees($current['temperature_f'])],
                ['Feels like', self::degrees($current['feels_like_f'])],
                ['Humidity', $current['humidity_pct'] !== null ? (int) round($current['humidity_pct']).'%' : self::DASH],
                ['Wind', self::wind($current['wind'])],
                ['Barometer', $current['barometer_inhg'] !== null ? number_format($current['barometer_inhg'], 2).' inHg' : self::DASH],
                ['Dewpoint', self::degrees($current['dewpoint_f'])],
                ['Visibility', $current['visibility_mi'] !== null ? round($current['visibility_mi'], 1).' mi' : self::DASH],
            ],
            'updated' => 'Last updated '.Carbon::parse($current['observed_at'])->format('D, M j \a\t g:i A'),
        ];
    }

    /**
     * @param  array{direction: ?string, speed_mph: ?float, gust_mph: ?float}  $wind
     */
    private static function wind(array $wind): string
    {
        if ($wind['speed_mph'] === null) {
            return self::DASH;
        }

        if ($wind['speed_mph'] < 0.5) {
            return 'Calm';
        }

        $text = trim(($wind['direction'] ?? '').' '.(int) round($wind['speed_mph']).' mph');

        return $wind['gust_mph'] !== null ? $text.', gusting '.(int) round($wind['gust_mph']).' mph' : $text;
    }

    private static function degrees(?float $value): string
    {
        return $value === null ? self::DASH : (int) round($value).'°F';
    }

    private static function temperature(mixed $value, string $unit): string
    {
        return is_numeric($value) ? (int) round((float) $value)."°{$unit}" : self::DASH;
    }
}
