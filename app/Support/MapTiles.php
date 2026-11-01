<?php

namespace App\Support;

use App\Enums\MapTileProvider;
use App\Settings\IntegrationSettings;
use App\Settings\SecretSettings;
use Throwable;

/**
 * The one source of the base-map tiles for every Leaflet map: the dashboard
 * widgets, the admin maps and (through ApplyMapTileSettings) the Pinpoint
 * location picker. OpenStreetMap's public tiles unless App settings switch on
 * a separate map tiler with a valid provider, style and key; then
 * OpenStreetMap is the fallback the browser switches to when the tiler's
 * tiles fail. Never throws: unreadable settings mean OpenStreetMap.
 *
 * The key ends up in every tile URL the browser requests, so it is not a
 * secret from page visitors, but it must never be logged.
 */
class MapTiles
{
    public const OSM_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';

    public const OSM_ATTRIBUTION = '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors';

    /**
     * Shared by every layer, so the fallback swaps only the URL and credit.
     * `{r}` in the URL handles high-density screens; detectRetina stays off.
     *
     * @var array{maxZoom: int, tileSize: int, zoomOffset: int, detectRetina: bool}
     */
    public const OPTIONS = ['maxZoom' => 19, 'tileSize' => 256, 'zoomOffset' => 0, 'detectRetina' => false];

    /**
     * @return array{
     *     provider: string,
     *     url: string,
     *     attribution: string,
     *     dark_url: null,
     *     options: array{maxZoom: int, tileSize: int, zoomOffset: int, detectRetina: bool},
     *     fallback: array{url: string, attribution: string, options: array{maxZoom: int, tileSize: int, zoomOffset: int, detectRetina: bool}}|null,
     *     match_prefix: string|null,
     *     logo: array{href: string, src: string, alt: string}|null
     * }
     */
    public static function forFrontend(): array
    {
        $tiler = self::configuredTiler();

        if ($tiler === null) {
            return [
                'provider' => 'OpenStreetMap',
                'url' => self::OSM_URL,
                'attribution' => self::OSM_ATTRIBUTION,
                'dark_url' => null,
                'options' => self::OPTIONS,
                'fallback' => null,
                'match_prefix' => null,
                'logo' => null,
            ];
        }

        [$provider, $style, $key] = $tiler;
        $url = $provider->tileUrl($style, $key);
        $logo = $provider->logo();

        return [
            'provider' => $provider->label(),
            'url' => $url,
            'attribution' => $provider->attribution($style),
            'dark_url' => null,
            'options' => self::OPTIONS,
            'fallback' => [
                'url' => self::OSM_URL,
                'attribution' => self::OSM_ATTRIBUTION,
                'options' => self::OPTIONS,
            ],
            'match_prefix' => strstr($url, '{z}', true) ?: null,
            'logo' => $logo === null ? null : [
                'href' => $logo['href'],
                'src' => $logo['hosted'] ? $logo['src'] : asset($logo['src']),
                'alt' => $logo['alt'],
            ],
        ];
    }

    /**
     * What every signed-in page's <head> needs to recognise the tiler's layers
     * (also Pinpoint's) and to fall back: the tile URL up to `{z}`, which
     * holds no key, never the URL itself. Only pages that render a map print
     * the full URL (forFrontend()).
     *
     * @return array{match_prefix: string|null, fallback: array{url: string, attribution: string, options: array{maxZoom: int, tileSize: int, zoomOffset: int, detectRetina: bool}}|null, logo: array{href: string, src: string, alt: string}|null}
     */
    public static function forHead(): array
    {
        $tiles = self::forFrontend();

        return [
            'match_prefix' => $tiles['match_prefix'],
            'fallback' => $tiles['fallback'],
            'logo' => $tiles['logo'],
        ];
    }

    /**
     * @return array{0: MapTileProvider, 1: string, 2: string}|null
     */
    private static function configuredTiler(): ?array
    {
        try {
            $settings = app(IntegrationSettings::class);

            if (! $settings->map_tiles_enabled) {
                return null;
            }

            $provider = MapTileProvider::tryFrom((string) $settings->map_tiles_provider);
            $style = $settings->map_tiles_style;

            if ($provider === null || ! $provider->hasStyle($style)) {
                return null;
            }

            $key = trim((string) app(SecretSettings::class)->map_tiles_api_key);
        } catch (Throwable) {
            return null;
        }

        return $key === '' ? null : [$provider, $style, $key];
    }
}
