<script>
    // Defined once per page, as a plain global function: Alpine/Livewire evaluate
    // x-init expressions in a wrapped scope that does not reliably resolve globals
    // such as `L` (see resources/views/filament/admin/pages/shapefile-layer-map.blade.php).
    if (! window.whenLeafletReady) {
        window.whenLeafletReady = function (callback) {
            if (window.L) { callback(); return; }

            document.addEventListener('pinpoint:leaflet:loaded', callback, { once: true });

            if (window.leafletLoading) { return; }

            window.leafletLoading = true;

            if (! document.getElementById('leaflet-css')) {
                const link = document.createElement('link');
                link.id = 'leaflet-css';
                link.rel = 'stylesheet';
                link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                document.head.appendChild(link);
            }

            const script = document.createElement('script');
            script.id = 'leaflet-js';
            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            script.onload = function () {
                window.leafletLoading = false;
                document.dispatchEvent(new CustomEvent('pinpoint:leaflet:loaded'));
            };
            document.head.appendChild(script);
        };
    }
</script>
