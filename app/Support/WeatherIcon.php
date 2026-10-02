<?php

namespace App\Support;

/**
 * Maps NWS's (deprecated, but still served) icon URLs to a self-hosted copy of
 * the same public-domain icon set, so nothing at runtime depends on NWS
 * continuing to serve them. The code is the first path segment after
 * /icons/land/{day|night}/ — NWS appends ",{probability}" and may join two
 * conditions with "/", both of which this ignores.
 */
class WeatherIcon
{
    private const FALLBACK = 'na';

    /**
     * @return array{period: string, code: string}
     */
    public static function fromUrl(?string $url): array
    {
        if ($url !== null && preg_match('#/icons/land/(day|night)/([a-z0-9_]+)#i', $url, $matches) === 1) {
            return ['period' => strtolower($matches[1]), 'code' => strtolower($matches[2])];
        }

        return ['period' => 'day', 'code' => self::FALLBACK];
    }

    public static function url(string $period, string $code): string
    {
        $period = $period === 'night' ? 'night' : 'day';

        if (preg_match('/^[a-z0-9_]+$/', $code) === 1 && $code !== self::FALLBACK) {
            $relative = "images/weather/{$period}/{$code}.png";

            if (is_file(public_path($relative))) {
                return self::absolute($relative);
            }
        }

        return self::absolute('images/weather/'.self::FALLBACK.'.png');
    }

    private static function absolute(string $relative): string
    {
        return rtrim((string) config('app.url'), '/').'/'.$relative;
    }
}
