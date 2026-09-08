<x-filament-panels::page>
    <div
        id="shapefile-layer-map"
        wire:ignore
        style="height: 600px;"
        x-data="{}"
        x-init="
            function initMap() {
                const map = L.map($el).setView([39.8283, -98.5795], 4);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap contributors',
                }).addTo(map);
                fetch(@js(route('admin.shapefile-layers.geojson', ['shapefileLayer' => $this->record->id])))
                    .then((response) => response.json())
                    .then((geojson) => {
                        const boundaryLayer = L.geoJSON(geojson).addTo(map);
                        if (geojson.features.length > 0) {
                            map.fitBounds(boundaryLayer.getBounds());
                        }
                    });
            }

            function loadLeaflet() {
                if (window.L) { initMap(); return; }

                if (window.leafletLoading) {
                    document.addEventListener('pinpoint:leaflet:loaded', initMap, { once: true });
                    return;
                }

                window.leafletLoading = true;

                if (!document.getElementById('leaflet-css')) {
                    const link = document.createElement('link');
                    link.id = 'leaflet-css';
                    link.rel = 'stylesheet';
                    link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                    document.head.appendChild(link);
                }

                const script = document.createElement('script');
                script.id = 'leaflet-js';
                script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                script.onload = () => {
                    window.leafletLoading = false;
                    document.dispatchEvent(new CustomEvent('pinpoint:leaflet:loaded'));
                    initMap();
                };
                document.head.appendChild(script);
            }

            loadLeaflet();
        "
    ></div>
</x-filament-panels::page>
