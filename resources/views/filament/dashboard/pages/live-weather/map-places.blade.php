{{--
    The My places layer for the Live map: the viewer's own saved places (server-picked and capped in
    Map::getPlacesConfig(), sent in config.places) as pins above the radar and SPC overlays and below popups.
    It is window.wxLiveMapPlaces, which the page's initLiveWeatherMap starts once Leaflet is ready. It asks the
    server for nothing. Names and addresses are the viewer's text: they go in with textContent only.
--}}
<script>
    // Defined once per page. Started by window.initLiveWeatherMap with { state, $wire, page };
    // window.destroyLiveWeatherMap calls destroy(state), which removes the layer and its listeners.
    if (! window.wxLiveMapPlaces) {
        window.wxLiveMapPlaces = (function () {
            // Shares the SPC layers' stored object; each module changes only its own keys.
            const STORAGE_KEY = 'wxnotify.liveMap.layers';
            // Above the outlooks, watches, warnings and discussion outlines (350-410), below the
            // default marker pane (600) and popups (700), so a pin is always clickable.
            const PANE = { name: 'wxLiveMapPlaces', zIndex: 420 };
            const PIN = '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="36" viewBox="0 0 28 36" aria-hidden="true" focusable="false" style="display:block"><path d="M14 1.5C7.1 1.5 1.5 7 1.5 13.8 1.5 22.6 14 34.5 14 34.5S26.5 22.6 26.5 13.8C26.5 7 20.9 1.5 14 1.5z" fill="#7c3aed" stroke="#ffffff" stroke-width="2.5"/><circle cx="14" cy="13.5" r="4.5" fill="#ffffff"/></svg>';

            function readStored() {
                try {
                    const parsed = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}');

                    return parsed && typeof parsed === 'object' ? parsed : {};
                } catch (error) {
                    return {};
                }
            }

            // Merged over the stored object so the SPC layers' choices are kept.
            function store(isOn) {
                try {
                    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(Object.assign({}, readStored(), { places: isOn })));
                } catch (error) {
                    // Blocked storage only means the choice is not remembered.
                }
            }

            // On unless the viewer turned it off.
            function startsOn(stored) {
                return stored.places !== false;
            }

            function isUsablePlace(place) {
                return !! place && typeof place === 'object' && typeof place.name === 'string'
                    && Number.isFinite(place.lat) && Number.isFinite(place.lng)
                    && Math.abs(place.lat) <= 90 && Math.abs(place.lng) <= 180;
            }

            function popupContent(place) {
                const box = document.createElement('div');
                const name = document.createElement('strong');

                name.textContent = place.name;
                box.appendChild(name);

                if (typeof place.detail === 'string' && place.detail !== '') {
                    const detail = document.createElement('div');

                    detail.textContent = place.detail;
                    box.appendChild(detail);
                }

                return box;
            }

            function init(context) {
                const state = context.state;
                const root = context.page.querySelector('[data-places-control]');
                const toggle = root ? root.querySelector('[data-places-toggle]') : null;
                const config = (state.config && state.config.places) || {};
                const places = (Array.isArray(config.places) ? config.places : []).filter(isUsablePlace);

                if (! state.map || ! toggle || places.length === 0) { return; }

                const map = state.map;

                if (! map.getPane(PANE.name)) { map.createPane(PANE.name).style.zIndex = String(PANE.zIndex); }

                const icon = L.divIcon({ html: PIN, className: '', iconSize: [28, 36], iconAnchor: [14, 34], popupAnchor: [0, -30] });
                const group = L.layerGroup();

                places.forEach((place) => {
                    const marker = L.marker([place.lat, place.lng], {
                        icon: icon, pane: PANE.name, title: place.name, keyboard: true, bubblingMouseEvents: false,
                    });

                    marker.bindPopup(popupContent(place));
                    marker.on('add', () => {
                        const element = marker.getElement && marker.getElement();

                        if (element && element.setAttribute) { element.setAttribute('aria-label', 'Saved location: ' + place.name); }
                    });
                    group.addLayer(marker);
                });

                let on = startsOn(readStored());

                function apply() {
                    if (on) {
                        if (! map.hasLayer(group)) { group.addTo(map); }
                    } else if (map.hasLayer(group)) {
                        map.removeLayer(group);
                    }
                }

                function onChange() {
                    on = !! toggle.checked;
                    store(on);
                    apply();
                }

                toggle.checked = on;
                toggle.addEventListener('change', onChange);
                apply();

                state.places = {
                    teardown: function () {
                        toggle.removeEventListener('change', onChange);
                        if (map.hasLayer(group)) { map.removeLayer(group); }
                        group.clearLayers();
                    },
                };
            }

            function destroy(state) {
                if (state.places && state.places.teardown) { state.places.teardown(); }

                state.places = null;
            }

            return { init: init, destroy: destroy, helpers: { startsOn: startsOn, isUsablePlace: isUsablePlace } };
        })();
    }
</script>
