<?php

namespace App\Enums;

/**
 * The separate map tilers an admin can choose instead of OpenStreetMap's
 * public tiles, with their raster tile URLs and required credits.
 *
 * Every URL is a 256 px XYZ template (Leaflet's default tile size, the only
 * one the Pinpoint location picker can use) with `{r}`, which Leaflet fills
 * with `@2x` on a high-density screen. The key is URL-encoded into the query.
 * Attributions are fixed, trusted markup: Leaflet renders them as HTML.
 */
enum MapTileProvider: string
{
    case MapTiler = 'maptiler';
    case Mapbox = 'mapbox';
    case StadiaMaps = 'stadia';
    case Thunderforest = 'thunderforest';

    private const OSM_CREDIT = '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap</a> contributors';

    public function label(): string
    {
        return match ($this) {
            self::MapTiler => 'MapTiler',
            self::Mapbox => 'Mapbox',
            self::StadiaMaps => 'Stadia Maps',
            self::Thunderforest => 'Thunderforest',
        };
    }

    /**
     * @return array<string, string> style id => label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $provider) {
            $options[$provider->value] = $provider->label();
        }

        return $options;
    }

    /**
     * @return array<string, string> style id => label
     */
    public function styles(): array
    {
        return match ($this) {
            self::MapTiler => ['streets-v4' => 'Streets', 'outdoor-v4' => 'Outdoor', 'hybrid' => 'Satellite (with labels)'],
            self::Mapbox => ['streets-v12' => 'Streets', 'outdoors-v12' => 'Outdoors', 'satellite-streets-v12' => 'Satellite (with streets)'],
            self::StadiaMaps => ['alidade_smooth' => 'Alidade Smooth', 'outdoors' => 'Outdoors', 'alidade_satellite' => 'Satellite'],
            self::Thunderforest => ['atlas' => 'Atlas', 'outdoors' => 'Outdoors', 'transport' => 'Transport'],
        };
    }

    public function hasStyle(?string $style): bool
    {
        return $style !== null && array_key_exists($style, $this->styles());
    }

    public function tileUrl(string $style, string $key): string
    {
        $key = rawurlencode($key);

        return match ($this) {
            self::MapTiler => "https://api.maptiler.com/maps/{$style}/256/{z}/{x}/{y}{r}.".($style === 'hybrid' ? 'jpg' : 'png')."?key={$key}",
            self::Mapbox => "https://api.mapbox.com/styles/v1/mapbox/{$style}/tiles/256/{z}/{x}/{y}{r}?access_token={$key}",
            self::StadiaMaps => "https://tiles.stadiamaps.com/tiles/{$style}/{z}/{x}/{y}{r}.".($style === 'alidade_satellite' ? 'jpg' : 'png')."?api_key={$key}",
            self::Thunderforest => "https://tile.thunderforest.com/{$style}/{z}/{x}/{y}{r}.png?apikey={$key}",
        };
    }

    /**
     * The provider logo a map must show bottom-left, linking to the provider,
     * or null when its terms ask only for the text credit (Stadia Maps,
     * Thunderforest). Mapbox requires its logo on every map, including in
     * Leaflet; the file is Mapbox's own SVG from its attribution guide,
     * bundled unmodified (see the comment inside it). MapTiler requires its
     * logo on Free plans and documents hot-linking its hosted file.
     *
     * @return array{href: string, src: string, alt: string, hosted: bool}|null
     */
    public function logo(): ?array
    {
        return match ($this) {
            self::Mapbox => ['href' => 'https://www.mapbox.com/', 'src' => 'images/map-logos/mapbox-logo.svg', 'alt' => 'Mapbox logo', 'hosted' => false],
            self::MapTiler => ['href' => 'https://www.maptiler.com/', 'src' => 'https://api.maptiler.com/resources/logo.svg', 'alt' => 'MapTiler logo', 'hosted' => true],
            self::StadiaMaps, self::Thunderforest => null,
        };
    }

    public function attribution(string $style): string
    {
        return match ($this) {
            self::MapTiler => '<a href="https://www.maptiler.com/copyright/" target="_blank" rel="noopener noreferrer">&copy; MapTiler</a> '.self::OSM_CREDIT,
            self::Mapbox => '&copy; <a href="https://www.mapbox.com/about/maps/" target="_blank" rel="noopener noreferrer">Mapbox</a> '.self::OSM_CREDIT
                .($style === 'satellite-streets-v12' ? ' &copy; <a href="https://www.maxar.com/" target="_blank" rel="noopener noreferrer">Maxar</a>' : '')
                .' <strong><a href="https://apps.mapbox.com/feedback/" target="_blank" rel="noopener noreferrer">Improve this map</a></strong>',
            self::StadiaMaps => ($style === 'alidade_satellite' ? '&copy; CNES, Distribution Airbus DS, &copy; Airbus DS, &copy; PlanetObserver (Contains Copernicus Data) | ' : '')
                .'&copy; <a href="https://stadiamaps.com/attribution/" target="_blank" rel="noopener noreferrer">Stadia Maps</a> &copy; <a href="https://openmaptiles.org/" target="_blank" rel="noopener noreferrer">OpenMapTiles</a> '.self::OSM_CREDIT,
            self::Thunderforest => 'Maps &copy; <a href="https://www.thunderforest.com/" target="_blank" rel="noopener noreferrer">Thunderforest</a>, Data '.self::OSM_CREDIT,
        };
    }
}
