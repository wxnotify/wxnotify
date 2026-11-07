{{--
    The recenter button for the Live map: a Leaflet control under the zoom buttons that takes the map back to the
    view the page opened with (the viewer's first saved place, or the whole United States when they have none).
    It is window.wxLiveMapRecenter, which the page's initLiveWeatherMap starts once Leaflet is ready. It asks for
    no browser location and reads no data; the server says (config.recenter) which name the button carries.
--}}
<script>
    // Defined once per page. Started by window.initLiveWeatherMap with { state, $wire, page };
    // window.destroyLiveWeatherMap calls destroy(state), which removes the control and its listeners.
    if (! window.wxLiveMapRecenter) {
        window.wxLiveMapRecenter = (function () {
            const FLY_SECONDS = 1;
            const ICON = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true" focusable="false" style="display:block;margin:auto"><circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2" fill="currentColor"/><path d="M12 1v4M12 19v4M1 12h4M19 12h4"/></svg>';

            function prefersReducedMotion() {
                try {
                    return !! (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
                } catch (error) {
                    return false;
                }
            }

            // Back to the opening view: no animation at all when the viewer prefers reduced motion.
            function returnToOpening(map, opening) {
                if (prefersReducedMotion()) {
                    map.setView(opening.center, opening.zoom, { animate: false });
                } else {
                    map.flyTo(opening.center, opening.zoom, { duration: FLY_SECONDS });
                }
            }

            function init(context) {
                const state = context.state;
                const config = (state.config && state.config.recenter) || {};
                const label = typeof config.label === 'string' && config.label !== '' ? config.label : 'Reset the map view';
                const control = new L.Control({ position: 'topleft' });
                let link = null;
                let onClick = null;
                let onKeydown = null;

                control.onAdd = function () {
                    const container = L.DomUtil.create('div', 'leaflet-bar leaflet-control');

                    link = L.DomUtil.create('a', '', container);
                    link.href = '#';
                    link.setAttribute('role', 'button');
                    link.setAttribute('aria-label', label);
                    link.title = label;
                    link.innerHTML = ICON;
                    link.style.display = 'flex';
                    link.style.alignItems = 'center';

                    onClick = function (event) {
                        event.preventDefault();
                        if (state.map && state.opening) { returnToOpening(state.map, state.opening); }
                    };
                    // A role=button link answers Space as well as Enter (Enter already clicks a link).
                    onKeydown = function (event) {
                        if (event.key === ' ' || event.key === 'Spacebar') { onClick(event); }
                    };

                    link.addEventListener('click', onClick);
                    link.addEventListener('keydown', onKeydown);
                    L.DomEvent.disableClickPropagation(container);

                    return container;
                };

                control.addTo(state.map);
                state.recenter = {
                    teardown: function () {
                        if (link) {
                            link.removeEventListener('click', onClick);
                            link.removeEventListener('keydown', onKeydown);
                        }
                        link = null;
                        control.remove();
                    },
                };
            }

            function destroy(state) {
                if (state.recenter && state.recenter.teardown) { state.recenter.teardown(); }

                state.recenter = null;
            }

            return { init: init, destroy: destroy, helpers: { returnToOpening: returnToOpening } };
        })();
    }
</script>
