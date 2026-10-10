<?php

namespace App\Support;

/**
 * The map's web page: one HTML document with Leaflet inlined, the layer configuration and the
 * snapshot's places, phone and warnings. It holds no token and is display-only (a popup per pin or
 * shape). Deterministic for a snapshot, so the web view is only reloaded when the snapshot changes.
 */
class MapPage
{
    private const JSON_FLAGS = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES;

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
            'basemaps' => $layers['basemaps'] ?? [],
            'radar' => $layers['radar'] ?? null,
            'attribution' => $layers['attribution'] ?? '',
        ];

        return view('map.page', [
            'leafletCss' => $this->leafletCss ?? file_get_contents(resource_path('map/leaflet.css')),
            'leafletJs' => $this->leafletJs ?? file_get_contents(resource_path('map/leaflet.js')),
            'json' => json_encode($data, self::JSON_FLAGS),
            'imageHosts' => $this->hosts($layers),
        ])->render();
    }

    /**
     * The https origins the page may load images from: the base maps' and the radar's.
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

                return isset($parts['host'], $parts['scheme']) && $parts['scheme'] === 'https' ? "https://{$parts['host']}" : null;
            })
            ->filter()->unique()->values()->all();
    }
}
