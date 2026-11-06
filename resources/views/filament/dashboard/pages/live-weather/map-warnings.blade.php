{{--
    The current short-fuse warnings for the Live map: the side panel beside the map (a drawer at the
    bottom of the screen below the lg breakpoint) and window.wxLiveMapWarnings, which the page's
    initLiveWeatherMap starts once Leaflet is ready. Events come from the page's liveWarnings()
    (the server checks the kind and the box); nothing is read while the page renders. Event names
    and headlines are outside text: they go in with textContent only.
--}}
@php
    $warningEventNames = $this->getWarningsConfig()['events'];
@endphp

<aside
    wire:ignore
    data-warnings-panel
    aria-label="Current warnings"
    class="fixed inset-x-0 bottom-0 z-40 flex max-h-[65vh] flex-col rounded-t-xl border border-gray-200 bg-white shadow-lg dark:border-white/10 dark:bg-gray-900 lg:static lg:z-auto lg:max-h-[70vh] lg:w-80 lg:shrink-0 lg:rounded-xl lg:shadow-sm"
>
    <button
        type="button"
        data-warnings-toggle
        aria-expanded="false"
        aria-controls="live-map-warnings-body"
        class="flex w-full items-center justify-between gap-2 px-4 py-3 text-left text-sm font-semibold text-gray-950 dark:text-white"
    >
        <span>Warnings <span data-warnings-count class="font-normal text-gray-500 dark:text-gray-400"></span></span>
        <span data-warnings-toggle-label class="text-xs font-normal text-gray-500 dark:text-gray-400">Show</span>
    </button>

    <div id="live-map-warnings-body" data-warnings-body hidden class="min-h-0 flex-1 space-y-3 overflow-y-auto border-t border-gray-200 px-4 py-3 dark:border-white/10">
        <p
            data-warnings-notice
            role="status"
            hidden
            class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"
        ></p>

        <p data-warnings-truncated hidden class="rounded-lg bg-gray-50 px-3 py-2 text-sm text-gray-700 dark:bg-white/5 dark:text-gray-300">Zoom in to see all warnings</p>

        <fieldset data-warnings-chips class="flex flex-wrap gap-2">
            <legend class="sr-only">Show these warnings</legend>
            @foreach ($warningEventNames as $warningEventName)
                <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-gray-300 px-2.5 py-1 text-xs text-gray-700 has-[:checked]:border-primary-500 has-[:checked]:bg-primary-50 dark:border-white/10 dark:text-gray-300 dark:has-[:checked]:bg-primary-500/10">
                    <input type="checkbox" data-warnings-chip value="{{ $warningEventName }}" checked class="h-3.5 w-3.5 rounded border-gray-300 dark:border-white/20">
                    <span>{{ $warningEventName }}</span>
                </label>
            @endforeach
        </fieldset>

        <p data-warnings-empty hidden class="text-sm text-gray-500 dark:text-gray-400">No warnings in view.</p>

        <ul data-warnings-list class="divide-y divide-gray-100 dark:divide-white/5"></ul>
    </div>
</aside>

<script>
    // Defined once per page. Started by window.initLiveWeatherMap with { state, $wire, page };
    // window.destroyLiveWeatherMap calls destroy(state). Outside text goes in with textContent only.
    if (! window.wxLiveMapWarnings) {
        window.wxLiveMapWarnings = (function () {
            const REFRESH_MS = 30 * 1000;
            const MOVE_DEBOUNCE_MS = 400;
            const MAX_WIDTH = 120;
            const MAX_HEIGHT = 60;
            const LIST_MAX_ZOOM = 10;
            const WIDE_QUERY = '(min-width: 1024px)';
            const UNAVAILABLE = 'Warnings are unavailable right now.';
            const FALLBACK_COLOR = '#6b7280';

            // --- Pure helpers (no Leaflet, no page) ---

            function clampNumber(value, low, high, fallback) {
                const number = Number(value);

                return Number.isFinite(number) ? Math.min(high, Math.max(low, number)) : fallback;
            }

            // The box to ask for: finite, within ±90/±180, south below north. A view wider than the
            // world is the whole world. A view across the antimeridian keeps the side holding more
            // of it (the west one on a tie) and is cut at ±180. `cut` says the viewer is not seeing
            // every warning in view: a side was dropped here, or the server will shrink the box
            // (wider than 120 or taller than 60 degrees).
            function clampBounds(raw) {
                let west = clampNumber(raw && raw.west, -Infinity, Infinity, -180);
                let east = clampNumber(raw && raw.east, -Infinity, Infinity, 180);
                let clipped = false;

                if (east - west >= 360) {
                    west = -180;
                    east = 180;
                } else {
                    const shift = Math.floor((west + 180) / 360) * 360;
                    west -= shift;
                    east -= shift;

                    if (east > 180) {
                        clipped = true;

                        if (east - 180 > 180 - west) {
                            west = -180;
                            east -= 360;
                        } else {
                            east = 180;
                        }
                    }
                }

                let south = clampNumber(raw && raw.south, -90, 90, -90);
                let north = clampNumber(raw && raw.north, -90, 90, 90);

                if (south > north) { [south, north] = [north, south]; }
                if (west > east) { [west, east] = [east, west]; }

                return {
                    bounds: { south: south, west: west, north: north, east: east },
                    cut: clipped || east - west > MAX_WIDTH || north - south > MAX_HEIGHT,
                };
            }

            function isEvent(entry) {
                return !! entry && typeof entry === 'object' && Number.isInteger(entry.id)
                    && typeof entry.event === 'string' && !! entry.geometry && typeof entry.geometry === 'object';
            }

            function expiryTime(entry) {
                const time = entry.expires ? Date.parse(entry.expires) : NaN;

                return isNaN(time) ? Infinity : time;
            }

            // Soonest end first, open-ended (or unreadable) last, then by id. Infinity - Infinity is
            // NaN, so two open-ended events fall through to the id.
            function compareByExpiry(a, b) {
                const difference = expiryTime(a) - expiryTime(b);

                return (Number.isNaN(difference) || difference === 0) ? a.id - b.id : difference;
            }

            function sortEntries(entries) {
                return entries.slice().sort(compareByExpiry);
            }

            function removedIds(previousIds, nextEntries) {
                const next = new Set(nextEntries.map((entry) => entry.id));

                return previousIds.filter((id) => ! next.has(id));
            }

            function safeColor(color) {
                return typeof color === 'string' && /^#[0-9a-f]{6}$/i.test(color) ? color : FALLBACK_COLOR;
            }

            // Only an https link to a product page is offered.
            function productUrl(entry) {
                if (typeof entry.view_url !== 'string') { return null; }

                try {
                    return new URL(entry.view_url).protocol === 'https:' ? entry.view_url : null;
                } catch (error) {
                    return null;
                }
            }

            function makeFormatter(timezone) {
                const options = { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' };

                try {
                    return new Intl.DateTimeFormat(undefined, timezone ? Object.assign({ timeZone: timezone }, options) : options);
                } catch (error) {
                    return new Intl.DateTimeFormat(undefined, options);
                }
            }

            function expiryLabel(formatter, entry) {
                const time = expiryTime(entry);

                return time === Infinity ? 'Until further notice' : 'Until ' + formatter.format(new Date(time));
            }

            // The popup's content as DOM nodes: event, headline, end and the product link.
            function popupContent(doc, formatter, entry) {
                const root = doc.createElement('div');
                const title = doc.createElement('strong');
                title.textContent = entry.event;
                root.appendChild(title);

                if (typeof entry.headline === 'string' && entry.headline !== '') {
                    const headline = doc.createElement('p');
                    headline.style.margin = '0.25rem 0 0';
                    headline.textContent = entry.headline;
                    root.appendChild(headline);
                }

                const ends = doc.createElement('p');
                ends.style.margin = '0.25rem 0 0';
                ends.textContent = expiryLabel(formatter, entry);
                root.appendChild(ends);

                const url = productUrl(entry);

                if (url) {
                    const link = doc.createElement('a');
                    link.href = url;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    link.textContent = 'View product';
                    link.style.display = 'inline-block';
                    link.style.marginTop = '0.25rem';
                    root.appendChild(link);
                }

                return root;
            }

            function prefersReducedMotion() {
                return !! (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
            }

            // --- The warnings on one page ---

            function init(context) {
                const state = context.state;
                const $wire = context.$wire;
                const root = context.page.querySelector('[data-warnings-panel]');

                if (! root || ! state.map) {
                    if (window.console) { console.warn('Live map: the warnings panel is missing, so warnings are off.'); }

                    return;
                }

                const map = state.map;
                const config = (state.config && state.config.warnings) || {};
                const ui = {
                    toggle: root.querySelector('[data-warnings-toggle]'),
                    toggleLabel: root.querySelector('[data-warnings-toggle-label]'),
                    count: root.querySelector('[data-warnings-count]'),
                    body: root.querySelector('[data-warnings-body]'),
                    notice: root.querySelector('[data-warnings-notice]'),
                    truncated: root.querySelector('[data-warnings-truncated]'),
                    empty: root.querySelector('[data-warnings-empty]'),
                    list: root.querySelector('[data-warnings-list]'),
                    chips: Array.from(root.querySelectorAll('[data-warnings-chip]')),
                };

                const warnings = state.warnings = {
                    kind: typeof config.kind === 'string' ? config.kind : 'short_fuse',
                    formatter: makeFormatter(config.timezone),
                    entries: [],
                    layers: new Map(),
                    hidden: new Set(),
                    popup: null,
                    request: 0,
                    moveTimer: null,
                    pendingFly: null,
                    loaded: false,
                    listeners: [],
                };

                function listen(target, type, handler) {
                    target.addEventListener(type, handler);
                    warnings.listeners.push(() => target.removeEventListener(type, handler));
                }

                function onMap(type, handler) {
                    map.on(type, handler);
                    warnings.listeners.push(() => map.off(type, handler));
                }

                function isShown(entry) {
                    return ! warnings.hidden.has(entry.event);
                }

                // --- The panel ---

                function setOpen(open) {
                    ui.body.hidden = ! open;
                    ui.toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
                    ui.toggleLabel.textContent = open ? 'Hide' : 'Show';
                    // The side column changes the map's width on wide screens.
                    setTimeout(() => { if (! state.destroyed && state.map) { state.map.invalidateSize(); } }, 0);
                }

                function setNotice(text) {
                    ui.notice.textContent = text;
                    ui.notice.hidden = text === '';
                }

                // --- Popups ---

                function closePopup() {
                    if (warnings.popup) {
                        const popup = warnings.popup.popup;
                        warnings.popup = null;
                        map.closePopup(popup);
                    }
                }

                function openPopup(entry, latlng) {
                    closePopup();

                    const popup = L.popup({ maxWidth: 280 })
                        .setLatLng(latlng)
                        .setContent(popupContent(document, warnings.formatter, entry));

                    warnings.popup = { id: entry.id, popup: popup };
                    popup.openOn(map);
                }

                onMap('popupclose', (event) => {
                    if (warnings.popup && event.popup === warnings.popup.popup) { warnings.popup = null; }
                });

                // --- Polygons ---

                function drawLayer(entry) {
                    const layer = L.geoJSON(entry.geometry, {
                        style: { color: safeColor(entry.color), weight: 2, opacity: 0.9, fillOpacity: 0.15 },
                    });

                    layer.on('click', (event) => openPopup(warnings.layers.get(entry.id).entry, event.latlng));

                    return layer;
                }

                function syncLayers(entries) {
                    const next = new Map();

                    entries.forEach((entry) => {
                        const geometryKey = JSON.stringify(entry.geometry) + '|' + safeColor(entry.color);
                        const existing = warnings.layers.get(entry.id);
                        let layer = existing && existing.geometryKey === geometryKey ? existing.layer : null;

                        if (existing && ! layer) { existing.layer.remove(); }
                        if (! layer) { layer = drawLayer(entry); }

                        next.set(entry.id, { layer: layer, entry: entry, geometryKey: geometryKey });
                    });

                    removedIds(Array.from(warnings.layers.keys()), entries).forEach((id) => {
                        warnings.layers.get(id).layer.remove();

                        if (warnings.popup && warnings.popup.id === id) { closePopup(); }
                    });

                    warnings.layers = next;

                    // An open popup shows the latest headline and end of its warning.
                    if (warnings.popup && next.has(warnings.popup.id)) {
                        warnings.popup.popup.setContent(popupContent(document, warnings.formatter, next.get(warnings.popup.id).entry));
                    }

                    showLayers();
                }

                function showLayers() {
                    warnings.layers.forEach((item) => {
                        const shown = isShown(item.entry);

                        if (shown && ! map.hasLayer(item.layer)) { item.layer.addTo(map); }
                        if (! shown && map.hasLayer(item.layer)) {
                            item.layer.remove();

                            if (warnings.popup && warnings.popup.id === item.entry.id) { closePopup(); }
                        }
                    });
                }

                // --- The list ---

                function cancelPendingFly() {
                    if (warnings.pendingFly) {
                        map.off('moveend', warnings.pendingFly);
                        warnings.pendingFly = null;
                    }
                }

                function flyTo(entry) {
                    const item = warnings.layers.get(entry.id);

                    if (! item) { return; }

                    const bounds = item.layer.getBounds();

                    if (! bounds.isValid()) { return; }

                    const target = bounds.getCenter();
                    const options = { maxZoom: LIST_MAX_ZOOM, padding: [24, 24] };
                    const openWhenThere = () => {
                        if (! state.destroyed && warnings.layers.has(entry.id)) { openPopup(warnings.layers.get(entry.id).entry, target); }
                    };

                    cancelPendingFly();

                    if (prefersReducedMotion()) {
                        map.fitBounds(bounds, Object.assign({ animate: false }, options));
                        openWhenThere();

                        return;
                    }

                    // Only the last row clicked opens its popup when the map arrives.
                    warnings.pendingFly = () => {
                        cancelPendingFly();
                        openWhenThere();
                    };
                    map.on('moveend', warnings.pendingFly);
                    map.flyToBounds(bounds, Object.assign({ duration: 0.75 }, options));
                }

                function listRow(entry) {
                    const row = document.createElement('li');
                    const button = document.createElement('button');
                    const swatch = document.createElement('span');
                    const text = document.createElement('span');
                    const name = document.createElement('span');
                    const ends = document.createElement('span');

                    button.type = 'button';
                    button.className = 'flex w-full items-start gap-2 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-white/5';
                    swatch.className = 'mt-1 inline-block h-3 w-3 shrink-0 rounded-sm';
                    swatch.style.backgroundColor = safeColor(entry.color);
                    swatch.setAttribute('aria-hidden', 'true');
                    text.className = 'min-w-0';
                    name.className = 'block font-medium text-gray-950 dark:text-white';
                    name.textContent = entry.event;
                    ends.className = 'block text-xs text-gray-500 dark:text-gray-400';
                    ends.textContent = expiryLabel(warnings.formatter, entry);

                    text.appendChild(name);
                    text.appendChild(ends);
                    button.appendChild(swatch);
                    button.appendChild(text);
                    button.addEventListener('click', () => flyTo(entry));
                    row.appendChild(button);

                    return row;
                }

                function renderList() {
                    const shown = warnings.entries.filter(isShown);

                    ui.list.replaceChildren(...shown.map(listRow));
                    ui.count.textContent = warnings.loaded ? '(' + shown.length + ')' : '';
                    ui.empty.hidden = ! warnings.loaded || shown.length > 0 || ! ui.notice.hidden;
                }

                // --- Asking the server ---

                async function refresh() {
                    const request = ++warnings.request;
                    const view = map.getBounds();
                    const box = clampBounds({
                        south: view.getSouth(), west: view.getWest(), north: view.getNorth(), east: view.getEast(),
                    });
                    let result = null;

                    try {
                        result = await $wire.liveWarnings(box.bounds, warnings.kind);
                    } catch (error) {
                        result = null;
                    }

                    if (state.destroyed || request !== warnings.request || ! state.map) { return; }

                    if (! result || result.status !== 'ok' || ! Array.isArray(result.events)) {
                        // Keep what is drawn; say it may be out of date rather than "no warnings".
                        setNotice(UNAVAILABLE);
                        renderList();

                        return;
                    }

                    try {
                        warnings.entries = sortEntries(result.events.filter(isEvent));
                        syncLayers(warnings.entries);
                        warnings.loaded = true;
                        setNotice('');
                        ui.truncated.hidden = ! (result.truncated === true || box.cut);
                        renderList();
                    } catch (error) {
                        if (window.console) { console.error(error); }
                        setNotice(UNAVAILABLE);
                        renderList();
                    }
                }

                // --- Wiring ---

                listen(ui.toggle, 'click', () => setOpen(ui.body.hidden));

                ui.chips.forEach((chip) => {
                    listen(chip, 'change', () => {
                        if (chip.checked) { warnings.hidden.delete(chip.value); } else { warnings.hidden.add(chip.value); }

                        showLayers();
                        renderList();
                    });
                });

                onMap('moveend', () => {
                    clearTimeout(warnings.moveTimer);
                    warnings.moveTimer = setTimeout(refresh, MOVE_DEBOUNCE_MS);
                });

                listen(document, 'visibilitychange', () => {
                    if (! document.hidden) { refresh(); }
                });

                warnings.teardown = () => {
                    clearTimeout(warnings.moveTimer);
                    warnings.request++;
                    cancelPendingFly();
                    closePopup();
                    warnings.listeners.forEach((remove) => remove());
                    warnings.listeners = [];
                    warnings.layers.forEach((item) => item.layer.remove());
                    warnings.layers = new Map();
                };

                setOpen(!! (window.matchMedia && window.matchMedia(WIDE_QUERY).matches));
                state.timers.push(setInterval(() => {
                    if (! document.hidden) { refresh(); }
                }, REFRESH_MS));
                refresh();
            }

            function destroy(state) {
                if (state.warnings && state.warnings.teardown) { state.warnings.teardown(); }

                state.warnings = null;
            }

            return {
                init: init,
                destroy: destroy,
                // Pure helpers, exposed for checks outside the page.
                helpers: {
                    clampBounds: clampBounds, sortEntries: sortEntries, removedIds: removedIds, productUrl: productUrl,
                    popupContent: popupContent, expiryLabel: expiryLabel, makeFormatter: makeFormatter, safeColor: safeColor, isEvent: isEvent,
                },
            };
        })();
    }
</script>
