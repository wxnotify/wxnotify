{{--
    The base-map tiles for every Leaflet map (App\Support\MapTiles). Printed in
    every admin and dashboard page's <head> (a panel render hook), so the
    fallback hook is installed before any map, the Pinpoint location picker
    included, creates its tile layer; map views include it as well so they
    work when rendered on their own. JSON with hex-escaped <, >, &, ' and ".
--}}
<script>
    window.wxMapTiles = {!! json_encode(\App\Support\MapTiles::forFrontend(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!};

    if (! window.wxInstallTileFallback) {
        // A separate tiler that fails (a wrong key, an exceeded quota) must not leave a blank gray map:
        // every layer drawn from the configured tiler URL switches itself, once and for good, to the
        // OpenStreetMap fallback. Two signals, because some tilers answer a bad key with an error IMAGE
        // (MapTiler, Stadia: 401/403 with a PNG body, which the <img> loads without a tileerror):
        // 1) a one-off fetch of tile 0/0/0 per page (shared by every map) that gets a non-OK status;
        // 2) ERRORS_BEFORE_FALLBACK tile errors in a row with no successful tile in between.
        // A fetch the browser refuses outright (CORS, offline) proves nothing and is ignored.
        window.wxInstallTileFallback = function () {
            const L = window.L;

            if (! L || ! L.TileLayer || L.TileLayer.wxFallbackInstalled) { return; }

            L.TileLayer.wxFallbackInstalled = true;

            const ERRORS_BEFORE_FALLBACK = 3;
            const probes = {};

            const useFallback = function (layer, fallback) {
                if (layer.wxFellBack) { return; }

                layer.wxFellBack = true;

                if (layer._map && layer._map.attributionControl) {
                    layer._map.attributionControl.removeAttribution(layer.options.attribution);
                    layer._map.attributionControl.addAttribution(fallback.attribution);
                }

                layer.options.attribution = fallback.attribution;
                L.Util.extend(layer.options, fallback.options);
                layer.setUrl(fallback.url);
            };

            const probe = function (layer, fallback) {
                if (! window.fetch) { return; }

                let url;

                try {
                    url = L.Util.template(layer._url, { s: 'a', r: '', x: 0, y: 0, z: 0 });
                } catch (error) {
                    return;
                }

                if (! probes[url]) {
                    probes[url] = fetch(url, { mode: 'cors', credentials: 'omit' })
                        .then((response) => response.ok, () => true);
                }

                probes[url].then((ok) => { if (! ok) { useFallback(layer, fallback); } });
            };

            L.TileLayer.addInitHook(function () {
                const tiles = window.wxMapTiles;

                if (! tiles || ! tiles.fallback || this._url !== tiles.url) { return; }

                const layer = this;
                const fallback = tiles.fallback;
                let errorsInARow = 0;

                layer.on('tileload', () => { errorsInARow = 0; });
                layer.on('tileerror', () => {
                    errorsInARow++;

                    if (errorsInARow >= ERRORS_BEFORE_FALLBACK) { useFallback(layer, fallback); }
                });
                layer.once('add', () => probe(layer, fallback));
            });
        };

        if (window.L) {
            window.wxInstallTileFallback();
        } else {
            document.addEventListener('pinpoint:leaflet:loaded', window.wxInstallTileFallback);
        }
    }

    if (! window.wxTileLayer) {
        // The base layer every map of ours adds: window.wxTileLayer().addTo(map).
        window.wxTileLayer = function () {
            window.wxInstallTileFallback();

            const tiles = window.wxMapTiles;

            return L.tileLayer(tiles.url, Object.assign({ attribution: tiles.attribution }, tiles.options));
        };
    }
</script>
