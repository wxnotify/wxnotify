<?php

namespace App\Support;

/**
 * The map's web page: one HTML document with Leaflet inlined, the layer configuration and the
 * snapshot's places, phone and warnings. It holds no token and is display-only (a popup per pin or
 * shape). Deterministic for a snapshot, so the web view is only reloaded when the snapshot changes.
 */
class MapPage
{
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;

    public function __construct(private ?string $leafletJs = null, private ?string $leafletCss = null) {}

    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function html(array $snapshot): string
    {
        $layers = $snapshot['layers'] ?? [];

        $data = [
            'center' => $snapshot['center'] ?? null,
            'phone' => $snapshot['phone'] ?? null,
            'places' => $snapshot['places'] ?? [],
            'warnings' => $snapshot['warnings'] ?? [],
            'basemaps' => $this->escapedBasemaps($layers['basemaps'] ?? []),
            'radar' => $layers['radar'] ?? null,
            'attribution' => $this->escaped($layers['attribution'] ?? ''),
        ];

        return view('map.page', [
            'leafletCss' => $this->leafletCss ?? file_get_contents(resource_path('map/leaflet.css')),
            'leafletJs' => $this->leafletJs ?? file_get_contents(resource_path('map/leaflet.js')),
            'json' => json_encode($data, self::JSON_FLAGS),
            'imageHosts' => $this->hosts($layers),
        ])->render();
    }

    /**
     * Leaflet writes base-map names and attribution with innerHTML, so they are escaped here. Place and warning text is
     * not: the page puts it in with text nodes.
     *
     * @param  list<array<string, mixed>>  $basemaps
     * @return list<array<string, mixed>>
     */
    private function escapedBasemaps(array $basemaps): array
    {
        return array_map(fn (array $basemap): array => [...$basemap, 'label' => $this->escaped($basemap['label'] ?? '')], $basemaps);
    }

    private function escaped(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * The https origins the page may load images from: the base maps' and the radar's. A host must be plain letters,
     * digits, dots and hyphens (so config cannot inject CSP directives); an explicit numeric port is kept.
     *
     * @param  array<string, mixed>  $layers
     * @return list<string>
     */
    private function hosts(array $layers): array
    {
        $urls = array_column($layers['basemaps'] ?? [], 'url');

        if (isset($layers['radar']['wms_url'])) {
            $urls[] = $layers['radar']['wms_url'];
        }

        return collect($urls)
            ->map(function (string $url): ?string {
                $parts = parse_url($url);

                if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || ! preg_match('/^[A-Za-z0-9.-]+$/', $parts['host'] ?? '')) {
                    return null;
                }

                return isset($parts['port']) ? "https://{$parts['host']}:{$parts['port']}" : "https://{$parts['host']}";
            })
            ->filter()->unique()->values()->all();
    }
}
