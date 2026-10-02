<x-filament-widgets::widget>
    <x-filament::section heading="Device map" compact>
        @include('filament.partials.leaflet-loader')

        {{-- wire:ignore on the root: Leaflet owns the map div and JS owns the caption. --}}
        <div
            wire:ignore
            x-data="{ state: {}, destroy() { window.destroyTraccarDevicesMap(this.state); } }"
            x-init="window.initTraccarDevicesMap($el, $wire, state)"
        >
            <div data-traccar-map style="height: 380px; border-radius: 0.5rem; overflow: hidden; position: relative; z-index: 0;"></div>
            <p data-traccar-caption class="mt-2 text-sm text-gray-500 dark:text-gray-400"></p>
        </div>

        <script>
            window.destroyTraccarDevicesMap = function (state) {
                state.destroyed = true;

                if (state.timer) { clearInterval(state.timer); state.timer = null; }
                if (state.map) { state.map.remove(); state.map = null; }
            };

            window.initTraccarDevicesMap = function (root, $wire, state) {
                const mapEl = root.querySelector('[data-traccar-map]');
                const captionEl = root.querySelector('[data-traccar-caption]');
                const DEFAULT_CENTER = [39.8283, -98.5795];
                const DEFAULT_ZOOM = 4;
                const REFRESH_MS = 60000;
                const COLORS = { fresh: '#dc2626', stale: '#9ca3af' };

                state.markers = new Map();
                state.fitted = false;
                state.destroyed = false;

                // The only markup built as HTML: a pin whose colour comes from the constants above.
                const pinIcon = (color) => L.divIcon({
                    className: '',
                    html: '<svg xmlns="http://www.w3.org/2000/svg" width="26" height="36" viewBox="0 0 26 36">'
                        + '<path d="M13 0C5.8 0 0 5.8 0 13c0 9.5 13 23 13 23s13-13.5 13-23C26 5.8 20.2 0 13 0z" fill="' + color + '" stroke="#ffffff" stroke-width="2"/>'
                        + '<circle cx="13" cy="13" r="5" fill="#ffffff"/></svg>',
                    iconSize: [26, 36],
                    iconAnchor: [13, 36],
                    popupAnchor: [0, -32],
                });

                const relativeTime = (date) => {
                    const seconds = Math.round((date.getTime() - Date.now()) / 1000);
                    const abs = Math.abs(seconds);
                    const rtf = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });

                    if (abs < 60) { return rtf.format(seconds, 'second'); }
                    if (abs < 3600) { return rtf.format(Math.round(seconds / 60), 'minute'); }
                    if (abs < 86400) { return rtf.format(Math.round(seconds / 3600), 'hour'); }

                    return rtf.format(Math.round(seconds / 86400), 'day');
                };

                const formatCheckin = (iso) => {
                    if (! iso) { return 'unknown'; }

                    const date = new Date(iso);

                    if (isNaN(date.getTime())) { return 'unknown'; }

                    const absolute = new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);

                    return absolute + ' · ' + relativeTime(date);
                };

                // DOM nodes and textContent only: server and device names are external strings.
                const popupContent = (device) => {
                    const wrap = document.createElement('div');

                    const line = (label, value) => {
                        const row = document.createElement('div');
                        const strong = document.createElement('strong');
                        strong.textContent = label + ' ';
                        row.appendChild(strong);
                        row.appendChild(document.createTextNode(value));
                        wrap.appendChild(row);
                    };

                    line('Server:', device.server);
                    line('Device:', device.name);
                    line('Last check-in:', formatCheckin(device.last_checkin));

                    if (device.stale) {
                        const note = document.createElement('div');
                        note.textContent = 'No check-in for over an hour';
                        note.style.color = '#6b7280';
                        wrap.appendChild(note);
                    }

                    return wrap;
                };

                const caption = (data) => {
                    const parts = [];

                    if (! data.has_servers) {
                        parts.push('Add a Traccar server to see its devices here.');
                    } else if (data.devices.length === 0) {
                        parts.push('No devices with a position yet.');
                    }

                    if (data.unplaced > 0) {
                        parts.push(data.unplaced + (data.unplaced === 1 ? ' device has' : ' devices have') + ' no position yet.');
                    }

                    if (data.unreachable.length > 0) {
                        parts.push("Couldn't reach: " + data.unreachable.join(', ') + '.');
                    }

                    captionEl.textContent = parts.join(' ');
                };

                const render = (data) => {
                    const seen = new Set();
                    const points = [];

                    data.devices.forEach((device) => {
                        seen.add(device.key);
                        points.push([device.lat, device.lng]);

                        const icon = pinIcon(device.stale ? COLORS.stale : COLORS.fresh);
                        const existing = state.markers.get(device.key);

                        if (existing) {
                            existing.setLatLng([device.lat, device.lng]);
                            existing.setIcon(icon);
                            existing.setPopupContent(popupContent(device));
                        } else {
                            const marker = L.marker([device.lat, device.lng], { icon: icon })
                                .bindPopup(popupContent(device))
                                .addTo(state.map);
                            state.markers.set(device.key, marker);
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
                    let data;

                    try {
                        data = await $wire.devices();
                    } catch (error) {
                        if (! state.destroyed) { captionEl.textContent = "Couldn't refresh the map."; }

                        return;
                    }

                    if (state.destroyed || ! state.map) { return; }

                    render(data);
                };

                window.whenLeafletReady(() => {
                    if (state.destroyed) { return; }

                    state.map = L.map(mapEl).setView(DEFAULT_CENTER, DEFAULT_ZOOM);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap contributors',
                    }).addTo(state.map);

                    refresh();
                    state.timer = setInterval(refresh, REFRESH_MS);
                });
            };
        </script>
    </x-filament::section>
</x-filament-widgets::widget>
