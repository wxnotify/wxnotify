<x-filament-panels::page>
    <div
        id="shapefile-layer-map"
        wire:ignore
        style="height: 600px;"
        x-data="{}"
        x-init="window.initShapefileLayerMap($el, @js(route('admin.shapefile-layers.geojson', ['shapefileLayer' => $this->record->id])))"
    ></div>

    <script>
        // Defined as a plain global function, not inline inside x-init: Alpine/Livewire
        // evaluate x-init expressions in a wrapped scope that does not reliably resolve
        // bare identifiers for locally-declared functions or globals like `L` (verified
        // directly -- the same code that throws "L is not defined" and "initMap is not
        // defined" when run as an x-init expression runs correctly as an ordinary
        // top-level script). Keeping the real logic in a normal <script> tag sidesteps
        // that entirely; x-init's job is reduced to one trivial function call.
        window.initShapefileLayerMap = function (el, geojsonUrl) {
            function initMap() {
                const map = L.map(el).setView([39.8283, -98.5795], 4);
                L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                    attribution: '&copy; OpenStreetMap contributors',
                }).addTo(map);
                fetch(geojsonUrl)
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
        };
    </script>
</x-filament-panels::page>
