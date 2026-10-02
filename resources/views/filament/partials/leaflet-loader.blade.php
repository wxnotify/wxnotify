<script>
    // Defined once per page, as a plain global function: Alpine/Livewire evaluate
    // x-init expressions in a wrapped scope that does not reliably resolve globals
    // such as `L` (see resources/views/filament/admin/pages/shapefile-layer-map.blade.php).
    // onFail is optional: it runs (once) if the Leaflet files cannot be loaded.
    if (! window.whenLeafletReady) {
        window.whenLeafletReady = function (callback, onFail) {
            if (window.L) { callback(); return; }

            document.addEventListener('pinpoint:leaflet:loaded', callback, { once: true });

            if (onFail) {
                document.addEventListener('pinpoint:leaflet:failed', onFail, { once: true });
            }

            if (window.leafletLoading) { return; }

            window.leafletLoading = true;

            // Leaflet 1.9.4 is pinned and integrity-checked; a changed file will not load.
            if (! document.getElementById('leaflet-css')) {
                const link = document.createElement('link');
                link.id = 'leaflet-css';
                link.rel = 'stylesheet';
                link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                link.integrity = 'sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=';
                link.crossOrigin = '';
                document.head.appendChild(link);
            }

            const script = document.createElement('script');
            script.id = 'leaflet-js';
            script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
            script.integrity = 'sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=';
            script.crossOrigin = '';
            script.onload = function () {
                window.leafletLoading = false;
                document.dispatchEvent(new CustomEvent('pinpoint:leaflet:loaded'));
            };
            script.onerror = function () {
                window.leafletLoading = false;
                script.remove();
                document.dispatchEvent(new CustomEvent('pinpoint:leaflet:failed'));
            };
            document.head.appendChild(script);
        };
    }
</script>
