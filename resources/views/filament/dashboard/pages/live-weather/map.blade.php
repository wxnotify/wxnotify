@php
    $opening = $this->getOpeningView();
    // Later tasks fill these keys (radar, warnings, layers); the script below reads them.
    $config = ['radar' => [], 'warnings' => [], 'layers' => []];
@endphp

<x-filament-panels::page>
    @include('filament.partials.leaflet-loader')
    @include('filament.partials.map-helpers')
    @include('filament.partials.map-tiles')

    {{-- wire:ignore on the root: Leaflet owns the map div. --}}
    <div
        wire:ignore
        x-data="{ state: {}, destroy() { window.destroyLiveWeatherMap(this.state); } }"
        x-init="window.initLiveWeatherMap($el, $wire, state)"
        data-live-weather-map
        data-center="{{ implode(',', $opening['center']) }}"
        data-zoom="{{ $opening['zoom'] }}"
        data-config="{{ json_encode($config, JSON_THROW_ON_ERROR) }}"
    >
        <div data-map style="height: 70vh; min-height: 480px; border-radius: 0.5rem; overflow: hidden; position: relative; z-index: 0;"></div>
        <p data-caption class="mt-2 text-sm text-gray-500 dark:text-gray-400"></p>
    </div>

    {{-- LATER TASKS: include the layer partials here, before the script (map-radar, map-warnings, map-layers). --}}

    <script>
        window.destroyLiveWeatherMap = function (state) {
            state.destroyed = true;

            (state.timers || []).forEach(clearInterval);
            state.timers = [];

            if (state.map) { state.map.remove(); state.map = null; }
        };

        window.initLiveWeatherMap = function (root, $wire, state) {
            const mapEl = root.querySelector('[data-map]');
            const captionEl = root.querySelector('[data-caption]');
            const center = root.dataset.center.split(',').map(Number);
            const zoom = Number(root.dataset.zoom);

            state.destroyed = false;
            state.timers = [];
            state.config = JSON.parse(root.dataset.config);

            window.whenLeafletReady(() => {
                if (state.destroyed) { return; }

                state.map = L.map(mapEl).setView(center, zoom);
                window.wxTileLayer().addTo(state.map);
            }, () => {
                if (! state.destroyed) { captionEl.textContent = "Couldn't load the map."; }
            });
        };
    </script>
</x-filament-panels::page>
