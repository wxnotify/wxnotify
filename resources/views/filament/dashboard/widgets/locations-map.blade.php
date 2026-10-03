<x-filament-widgets::widget>
    <x-filament::section heading="Location map" compact>
        @include('filament.partials.leaflet-loader')
        @include('filament.partials.map-helpers')

        {{-- wire:ignore on the root: Leaflet owns the map div and JS owns the caption. --}}
        <div
            wire:ignore
            x-data="{ state: {}, destroy() { window.destroyLocationsMap(this.state); } }"
            x-init="window.initLocationsMap($el, $wire, state)"
        >
            <div data-locations-map style="height: 380px; border-radius: 0.5rem; overflow: hidden; position: relative; z-index: 0;"></div>
            <p data-locations-caption class="mt-2 text-sm text-gray-500 dark:text-gray-400"></p>
        </div>

        <script>
            window.destroyLocationsMap = function (state) {
                state.destroyed = true;

                if (state.timer) { clearInterval(state.timer); state.timer = null; }
                if (state.map) { state.map.remove(); state.map = null; }
            };

            window.initLocationsMap = function (root, $wire, state) {
                const mapEl = root.querySelector('[data-locations-map]');
                const captionEl = root.querySelector('[data-locations-caption]');
                const DEFAULT_CENTER = [39.8283, -98.5795];
                const DEFAULT_ZOOM = 4;
                const REFRESH_MS = 60000;
                const COLORS = { stationary: '#dc2626', mobile: '#2563eb', stale: '#9ca3af' };

                state.markers = new Map();
                state.fitted = false;
                state.destroyed = false;

                const colorFor = (location) => location.stale ? COLORS.stale : (location.type === 'mobile' ? COLORS.mobile : COLORS.stationary);

                // DOM nodes and textContent only: labels and addresses are subscriber-supplied strings.
                const popupContent = (location) => {
                    const wrap = document.createElement('div');

                    const line = (label, value) => {
                        const row = document.createElement('div');
                        const strong = document.createElement('strong');
                        strong.textContent = label + ' ';
                        row.appendChild(strong);
                        row.appendChild(document.createTextNode(value));
                        wrap.appendChild(row);
                    };

                    line('Name:', location.label);
                    line('Type:', location.type === 'mobile' ? 'Mobile' : 'Stationary');

                    if (location.type === 'mobile') {
                        line('Last position update:', window.wxMapHelpers.formatTimestamp(location.updated_at));

                        if (location.stale) {
                            const note = document.createElement('div');
                            note.textContent = 'No update for over an hour';
                            note.style.color = '#6b7280';
                            wrap.appendChild(note);
                        }
                    } else if (location.address) {
                        line('Address:', location.address);
                    }

                    const link = document.createElement('a');
                    link.href = location.edit_url;
                    link.textContent = 'Edit';
                    link.style.display = 'inline-block';
                    link.style.marginTop = '0.25rem';
                    wrap.appendChild(link);

                    return wrap;
                };

                const caption = (data) => {
                    const parts = [];

                    if (data.locations.length === 0 && data.unplaced === 0) {
                        parts.push('Add a location to see it here.');
                    } else if (data.locations.length === 0) {
                        parts.push('No locations with a position yet.');
                    }

                    if (data.unplaced > 0) {
                        parts.push(data.unplaced + (data.unplaced === 1 ? ' mobile location has' : ' mobile locations have') + ' no position yet.');
                    }

                    captionEl.textContent = parts.join(' ');
                };

                const render = (data) => {
                    const seen = new Set();
                    const points = [];

                    data.locations.forEach((location) => {
                        seen.add(location.key);
                        points.push([location.lat, location.lng]);

                        const icon = window.wxMapHelpers.pinIcon(colorFor(location));
                        const existing = state.markers.get(location.key);

                        if (existing) {
                            existing.setLatLng([location.lat, location.lng]);
                            existing.options.title = existing.options.alt = location.label;
                            existing.setIcon(icon);
                            existing.setPopupContent(popupContent(location));
                        } else {
                            const marker = L.marker([location.lat, location.lng], { icon: icon, title: location.label, alt: location.label })
                                .bindPopup(popupContent(location))
                                .addTo(state.map);
                            state.markers.set(location.key, marker);
                        }
                    });

                    state.markers.forEach((marker, key) => {
                        if (! seen.has(key)) {
                            marker.remove();
                            state.markers.delete(key);
                        }
                    });

                    if (points.length > 0 && ! state.fitted) {
                        state.map.fitBounds(L.latLngBounds(points), { padding: [40, 40], maxZoom: 12 });
                        state.fitted = true;
                    }

                    caption(data);
                };

                const refresh = async () => {
                    try {
                        const data = await $wire.locations();

                        if (state.destroyed || ! state.map) { return; }

                        if (! data || ! Array.isArray(data.locations)) {
                            throw new Error('Unexpected map payload');
                        }

                        render(data);
                    } catch (error) {
                        if (! state.destroyed) { captionEl.textContent = "Couldn't refresh the map."; }
                    }
                };

                window.whenLeafletReady(() => {
                    if (state.destroyed) { return; }

                    state.map = L.map(mapEl).setView(DEFAULT_CENTER, DEFAULT_ZOOM);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap contributors',
                    }).addTo(state.map);

                    refresh();
                    state.timer = setInterval(refresh, REFRESH_MS);
                }, () => {
                    if (! state.destroyed) { captionEl.textContent = "Couldn't load the map."; }
                });
            };
        </script>
    </x-filament::section>
</x-filament-widgets::widget>
