{{--
    The base-map tiles for a page that renders a Leaflet map (App\Support\MapTiles::forFrontend()),
    including the tiler's key, which is in every tile URL anyway. Include it only in map views; the
    key-free fallback part is also printed in every signed-in page's <head>. JSON with hex-escaped
    <, >, &, ' and ".
--}}
@include('filament.partials.map-tile-fallback')
<script>
    window.wxMapTiles = {!! json_encode(\App\Support\MapTiles::forFrontend(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!};

    if (! window.wxTileLayer) {
        // The base layer every map of ours adds: window.wxTileLayer().addTo(map).
        window.wxTileLayer = function () {
            window.wxInstallTileFallback();

            const tiles = window.wxMapTiles;

            return L.tileLayer(tiles.url, Object.assign({ attribution: tiles.attribution }, tiles.options));
        };
    }
</script>
