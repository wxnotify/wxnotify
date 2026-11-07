@php
    $opening = $this->getOpeningView();
    $config = ['radar' => $this->getRadarConfig(), 'warnings' => $this->getWarningsConfig(), 'layers' => $this->getLayersConfig(), 'recenter' => $this->getRecenterConfig(), 'places' => $this->getPlacesConfig()];
@endphp

<x-filament-panels::page>
    @include('filament.partials.leaflet-loader')
    @include('filament.partials.map-helpers')
    @include('filament.partials.map-tiles')

    {{-- The map and its layer controls; each layer module finds its controls inside this wrapper. --}}
    <div data-live-weather-page class="pb-14 lg:pb-0">
        {{-- The map, with the warnings panel beside it (a drawer at the bottom on small screens). --}}
        <div class="lg:flex lg:items-start lg:gap-3">
            {{-- wire:ignore on the root: Leaflet owns the map div. --}}
            <div
                wire:ignore
                x-data="{ state: {}, destroy() { window.destroyLiveWeatherMap(this.state); } }"
                x-init="window.initLiveWeatherMap($el, $wire, state)"
                data-live-weather-map
                data-center="{{ implode(',', $opening['center']) }}"
                data-zoom="{{ $opening['zoom'] }}"
                data-config="{{ json_encode($config, JSON_THROW_ON_ERROR) }}"
                class="min-w-0 lg:flex-1"
            >
                <div data-map style="height: 70vh; min-height: 480px; border-radius: 0.5rem; overflow: hidden; position: relative; z-index: 0;"></div>
                <p data-caption class="mt-2 text-sm text-gray-500 dark:text-gray-400"></p>
            </div>

            @include('filament.dashboard.pages.live-weather.map-warnings')
        </div>

        {{-- The other layer partials, before the script. --}}
        @include('filament.dashboard.pages.live-weather.map-radar')
        @include('filament.dashboard.pages.live-weather.map-layers')
        @include('filament.dashboard.pages.live-weather.map-recenter')
        @include('filament.dashboard.pages.live-weather.map-places')
    </div>

    <script>
        // Defined once per page. Each layer partial defines a window.wxLiveMap* module with
        // init(context) and destroy(state); a failing layer never stops the map or the others.
        if (! window.destroyLiveWeatherMap) {
            window.destroyLiveWeatherMap = function (state) {
                state.destroyed = true;

                (state.modules || []).forEach((module) => {
                    try { module.destroy(state); } catch (error) { /* a layer's teardown never blocks the map's */ }
                });
                state.modules = [];

                (state.timers || []).forEach(clearInterval);
                state.timers = [];

                if (state.map) { state.map.remove(); state.map = null; }
            };
        }

        if (! window.initLiveWeatherMap) {
            window.initLiveWeatherMap = function (root, $wire, state) {
                const mapEl = root.querySelector('[data-map]');
                const captionEl = root.querySelector('[data-caption]');
                const center = root.dataset.center.split(',').map(Number);
                const zoom = Number(root.dataset.zoom);
                const page = root.closest('[data-live-weather-page]') || document;

                state.destroyed = false;
                state.timers = [];
                state.modules = [];
                state.config = JSON.parse(root.dataset.config);
                state.opening = { center: center, zoom: zoom };

                const startModule = (module) => {
                    if (! module) { return; }

                    try {
                        state.modules.push(module);
                        module.init({ state: state, $wire: $wire, page: page });
                    } catch (error) {
                        if (window.console) { console.error(error); }
                    }
                };

                window.whenLeafletReady(() => {
                    if (state.destroyed) { return; }

                    state.map = L.map(mapEl).setView(center, zoom);
                    window.wxTileLayer().addTo(state.map);

                    startModule(window.wxLiveMapRadar);
                    startModule(window.wxLiveMapWarnings);
                    startModule(window.wxLiveMapLayers);
                    startModule(window.wxLiveMapRecenter);
                    startModule(window.wxLiveMapPlaces);
                }, () => {
                    if (! state.destroyed) { captionEl.textContent = "Couldn't load the map."; }
                });
            };
        }
    </script>
</x-filament-panels::page>
