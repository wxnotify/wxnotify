{{--
    Key-free part of the base-map tiles (App\Support\MapTiles::forHead()), printed in the <head> of every
    signed-in Admin and Dashboard page (a panel render hook) so the fallback hook is installed before any
    map, the Pinpoint location picker included, creates its tile layer. It recognises the separate tiler's
    layers by the URL up to {z}, which holds no key: the full URL with the key is printed only by pages
    that render a map (filament.partials.map-tiles, and Pinpoint's own view). JSON with hex-escaped
    <, >, &, ' and ".
--}}
<script>
    window.wxMapTileFallback = {!! json_encode(\App\Support\MapTiles::forHead(), JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!};

    if (! window.wxInstallTileFallback) {
        // A separate tiler that fails (a wrong key, an exceeded quota) must not leave a blank gray map:
        // every layer drawn from the configured tiler (its URL starts with match_prefix) switches itself,
        // once and for good, to the OpenStreetMap fallback. Two signals, because some tilers answer a bad
        // key with an error IMAGE (MapTiler, Stadia: 401/403 with a PNG body, which the <img> loads
        // without a tileerror):
        // 1) a one-off fetch of tile 0/0/0 per page (shared by every map) answered 401, 403 or 429
        //    (rejected key or spent quota); a 5xx, another status or a fetch the browser refuses outright
        //    (CORS, offline) proves nothing and is ignored;
        // 2) ERRORS_BEFORE_FALLBACK tile errors in a row with no successful tile in between.
        // A provider whose terms require its logo (Mapbox, MapTiler) gets it as a bottom-left control,
        // removed when the layer falls back or leaves the map.
        window.wxInstallTileFallback = function () {
            const L = window.L;

            if (! L || ! L.TileLayer || L.TileLayer.wxFallbackInstalled) { return; }

            L.TileLayer.wxFallbackInstalled = true;

            const ERRORS_BEFORE_FALLBACK = 3;
            const probes = {};

            const useFallback = function (layer, fallback) {
                if (layer.wxFellBack) { return; }

                layer.wxFellBack = true;

                if (layer.wxLogoControl) {
                    layer.wxLogoControl.remove();
                    layer.wxLogoControl = null;
                }

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
                        .then((response) => ! [401, 403, 429].includes(response.status), () => true);
                }

                probes[url].then((ok) => { if (! ok) { useFallback(layer, fallback); } });
            };

            const addLogo = function (layer, logo) {
                if (! logo || layer.wxFellBack || layer.wxLogoControl || ! layer._map) { return; }

                const control = L.control({ position: 'bottomleft' });

                // DOM nodes only; every value comes from MapTileProvider::logo() (fixed, trusted).
                control.onAdd = function () {
                    const link = L.DomUtil.create('a', 'wx-map-logo');
                    link.href = logo.href;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';

                    const image = L.DomUtil.create('img', '', link);
                    image.src = logo.src;
                    image.alt = logo.alt;
                    image.height = 20;
                    image.style.display = 'block';

                    L.DomEvent.disableClickPropagation(link);

                    return link;
                };

                control.addTo(layer._map);
                layer.wxLogoControl = control;
            };

            L.TileLayer.addInitHook(function () {
                const tiles = window.wxMapTileFallback;
                const prefix = tiles && tiles.match_prefix;

                if (! prefix || ! tiles.fallback || typeof this._url !== 'string' || this._url.indexOf(prefix) !== 0) { return; }

                const layer = this;
                const fallback = tiles.fallback;
                let errorsInARow = 0;

                layer.on('tileload', () => { errorsInARow = 0; });
                layer.on('tileerror', () => {
                    errorsInARow++;

                    if (errorsInARow >= ERRORS_BEFORE_FALLBACK) { useFallback(layer, fallback); }
                });
                layer.on('add', () => addLogo(layer, tiles.logo));
                layer.on('remove', () => {
                    if (layer.wxLogoControl) {
                        layer.wxLogoControl.remove();
                        layer.wxLogoControl = null;
                    }
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
</script>
