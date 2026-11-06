{{--
    The SPC layers for the Live map: the layer control under the radar controls and
    window.wxLiveMapLayers, which the page's initLiveWeatherMap starts once Leaflet is ready.
    Tornado and Severe Thunderstorm Watches come from the page's liveWarnings() (the watches kind),
    the convective outlook from spcOutlook(day) and the Mesoscale Discussions from
    mesoscaleDiscussions(); nothing is read while the page renders. Every layer is off until the
    viewer turns it on. Outside text (event names, headlines, areas) goes in with textContent only.
--}}
@php
    $layerSelectClasses = 'block rounded-lg border-gray-300 bg-white py-1.5 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white';
    $layerToggleClasses = 'h-4 w-4 rounded border-gray-300 dark:border-white/20';
    $outlookDays = $this->getLayersConfig()['outlook_days'];
@endphp

<div wire:ignore data-layers-control class="mt-3 space-y-3">
    <p
        data-layers-notice
        role="status"
        hidden
        class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"
    ></p>

    <fieldset class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-gray-700 dark:text-gray-300">
        <legend class="mb-1 font-medium">SPC layers</legend>
        <label class="inline-flex cursor-pointer items-center gap-1.5">
            <input type="checkbox" data-layer-toggle="watches" class="{{ $layerToggleClasses }}">
            <span>Tornado and severe thunderstorm watches</span>
        </label>
        <label class="inline-flex cursor-pointer items-center gap-1.5">
            <input type="checkbox" data-layer-toggle="outlook" class="{{ $layerToggleClasses }}">
            <span>Convective outlook</span>
        </label>
        <label class="inline-flex cursor-pointer items-center gap-1.5">
            <input type="checkbox" data-layer-toggle="discussions" class="{{ $layerToggleClasses }}">
            <span>Mesoscale Discussions</span>
        </label>
    </fieldset>

    <div data-outlook-options hidden class="flex flex-wrap items-center gap-3">
        <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
            <span class="font-medium">Outlook</span>
            <select data-outlook-day class="{{ $layerSelectClasses }}">
                @foreach ($outlookDays as $outlookDay)
                    <option value="{{ $outlookDay }}">Day {{ $outlookDay }}</option>
                @endforeach
            </select>
        </label>

        <ul data-outlook-legend aria-label="Convective outlook categories" class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-gray-700 dark:text-gray-300">
            @foreach (\App\Support\SpcOutlookColors::legend() as $outlookCategory)
                <li class="inline-flex items-center gap-1">
                    <span aria-hidden="true" class="inline-block h-3 w-3 rounded-sm border border-black/10" style="background-color: {{ $outlookCategory['color'] }}"></span>
                    <span>{{ $outlookCategory['name'] }}</span>
                </li>
            @endforeach
        </ul>

        <span data-outlook-status class="text-xs text-gray-500 dark:text-gray-400"></span>
    </div>
</div>

<script>
    // Defined once per page. Started by window.initLiveWeatherMap with { state, $wire, page };
    // window.destroyLiveWeatherMap calls destroy(state). It reuses the warnings module's helpers
    // (bounds, colours, popups, links, times). Outside text goes in with textContent only.
    if (! window.wxLiveMapLayers) {
        window.wxLiveMapLayers = (function () {
            const STORAGE_KEY = 'wxnotify.liveMap.layers';
            const WATCHES_REFRESH_MS = 30 * 1000;
            const SPC_REFRESH_MS = 5 * 60 * 1000;
            const MOVE_DEBOUNCE_MS = 400;
            const KINDS = ['watches', 'outlook', 'discussions'];
            const DEFAULT_DAYS = [1, 2, 3];
            // The outlook lies under the warnings (the overlay pane is 400), the watches just
            // under them, and the discussions' outlines above them (stroke only, so a click
            // inside one still reaches the warning beneath).
            const PANES = {
                outlook: { name: 'wxLiveMapOutlook', zIndex: 350 },
                watches: { name: 'wxLiveMapWatches', zIndex: 390 },
                discussions: { name: 'wxLiveMapDiscussions', zIndex: 410 },
            };
            const DISCUSSION_COLOR = '#1d4ed8';
            const MESSAGES = {
                watches: 'Watches are unavailable right now.',
                discussions: 'Mesoscale Discussions are unavailable right now.',
            };

            // --- Stored choices: storage may be blocked, so every read and write is guarded. ---

            function readStoredChoices() {
                try {
                    const parsed = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}');

                    return parsed && typeof parsed === 'object' ? parsed : {};
                } catch (error) {
                    return {};
                }
            }

            function storeChoices(choices) {
                try {
                    window.localStorage.setItem(STORAGE_KEY, JSON.stringify(choices));
                } catch (error) {
                    // Blocked storage only means the choices are not remembered.
                }
            }

            // Every layer is off unless the viewer turned it on; the day must be one offered.
            function startingChoices(stored, days) {
                return {
                    watches: stored.watches === true,
                    outlook: stored.outlook === true,
                    discussions: stored.discussions === true,
                    day: days.includes(stored.day) ? stored.day : days[0],
                };
            }

            // --- Pure helpers ---

            function outlookUnavailable(day) {
                return 'The Day ' + day + ' convective outlook is unavailable right now.';
            }

            // A day with no areas is a genuine quiet day (only an available answer reaches here).
            function outlookStatus(formatter, day, issuedAt, count) {
                if (count === 0) { return 'No thunderstorm areas in the Day ' + day + ' outlook.'; }

                const time = typeof issuedAt === 'string' ? Date.parse(issuedAt) : NaN;

                return isNaN(time) ? '' : 'Issued ' + formatter.format(new Date(time));
            }

            function isOutlookFeature(entry) {
                return !! entry && typeof entry === 'object' && typeof entry.label === 'string'
                    && !! entry.geometry && typeof entry.geometry === 'object';
            }

            function isDiscussion(entry) {
                return !! entry && typeof entry === 'object' && Number.isInteger(entry.id)
                    && typeof entry.expires === 'string' && !! entry.geometry && typeof entry.geometry === 'object';
            }

            function noticeText(notices) {
                return KINDS.map((kind) => notices[kind]).filter(Boolean).join(' ');
            }

            // The discussion popup as DOM nodes: number, areas, end and the product link.
            function discussionContent(doc, shared, formatter, entry) {
                const root = doc.createElement('div');
                const title = doc.createElement('strong');
                title.textContent = typeof entry.number === 'string' && entry.number !== ''
                    ? 'Mesoscale Discussion ' + entry.number
                    : 'Mesoscale Discussion';
                root.appendChild(title);

                if (typeof entry.areas === 'string' && entry.areas !== '') {
                    const areas = doc.createElement('p');
                    areas.style.margin = '0.25rem 0 0';
                    areas.textContent = 'Areas affected: ' + entry.areas;
                    root.appendChild(areas);
                }

                const ends = doc.createElement('p');
                ends.style.margin = '0.25rem 0 0';
                ends.textContent = shared.expiryLabel(formatter, entry);
                root.appendChild(ends);

                const url = shared.productUrl(entry);

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

            // --- The layers on one page ---

            function init(context) {
                const state = context.state;
                const $wire = context.$wire;
                const root = context.page.querySelector('[data-layers-control]');
                const shared = window.wxLiveMapWarnings && window.wxLiveMapWarnings.helpers;

                if (! root || ! state.map || ! shared) {
                    if (window.console) { console.warn('Live map: the SPC layer control is missing, so the SPC layers are off.'); }

                    return;
                }

                const map = state.map;
                const config = (state.config && state.config.layers) || {};
                const days = Array.isArray(config.outlook_days) && config.outlook_days.some(Number.isInteger)
                    ? config.outlook_days.filter(Number.isInteger)
                    : DEFAULT_DAYS;
                const ui = {
                    notice: root.querySelector('[data-layers-notice]'),
                    options: root.querySelector('[data-outlook-options]'),
                    day: root.querySelector('[data-outlook-day]'),
                    status: root.querySelector('[data-outlook-status]'),
                    toggles: {},
                };

                KINDS.forEach((kind) => { ui.toggles[kind] = root.querySelector('[data-layer-toggle="' + kind + '"]'); });

                const layers = state.spcLayers = {
                    choices: startingChoices(readStoredChoices(), days),
                    watchesKind: typeof config.watches_kind === 'string' ? config.watches_kind : 'watches',
                    formatter: shared.makeFormatter(config.timezone),
                    groups: {},
                    entries: { watches: new Map(), discussions: new Map() },
                    requests: { watches: 0, outlook: 0, discussions: 0 },
                    notices: {},
                    outlookDay: null,
                    popup: null,
                    moveTimer: null,
                    listeners: [],
                };

                function listen(target, type, handler) {
                    target.addEventListener(type, handler);
                    layers.listeners.push(() => target.removeEventListener(type, handler));
                }

                function onMap(type, handler) {
                    map.on(type, handler);
                    layers.listeners.push(() => map.off(type, handler));
                }

                KINDS.forEach((kind) => {
                    if (! map.getPane(PANES[kind].name)) {
                        map.createPane(PANES[kind].name).style.zIndex = String(PANES[kind].zIndex);
                    }

                    layers.groups[kind] = L.layerGroup();
                });

                // An answer is used only while its layer is on and no newer request was made.
                function isStale(kind, request) {
                    return state.destroyed || ! state.map || request !== layers.requests[kind] || ! layers.choices[kind];
                }

                function setNotice(kind, text) {
                    if (text) { layers.notices[kind] = text; } else { delete layers.notices[kind]; }

                    const combined = noticeText(layers.notices);
                    ui.notice.textContent = combined;
                    ui.notice.hidden = combined === '';
                }

                // --- Popups (watches and discussions) ---

                function popupContent(kind, entry) {
                    return kind === 'watches'
                        ? shared.popupContent(document, layers.formatter, entry)
                        : discussionContent(document, shared, layers.formatter, entry);
                }

                function closePopup() {
                    if (layers.popup) {
                        const popup = layers.popup.popup;
                        layers.popup = null;
                        map.closePopup(popup);
                    }
                }

                function openPopup(kind, id, latlng) {
                    const entry = layers.entries[kind].get(id);

                    if (! entry) { return; }

                    closePopup();

                    const popup = L.popup({ maxWidth: 280 }).setLatLng(latlng).setContent(popupContent(kind, entry));
                    layers.popup = { kind: kind, id: id, popup: popup };
                    popup.openOn(map);
                }

                onMap('popupclose', (event) => {
                    if (layers.popup && event.popup === layers.popup.popup) { layers.popup = null; }
                });

                // --- Drawing ---

                function drawEntries(kind, entries, style) {
                    const group = layers.groups[kind];
                    const next = new Map();

                    group.clearLayers();

                    entries.forEach((entry) => {
                        const layer = L.geoJSON(entry.geometry, { pane: PANES[kind].name, style: style(entry) });

                        layer.on('click', (event) => openPopup(kind, entry.id, event.latlng));
                        group.addLayer(layer);
                        next.set(entry.id, entry);
                    });

                    layers.entries[kind] = next;

                    // An open popup shows the latest text of its entry, or closes when it is gone.
                    if (layers.popup && layers.popup.kind === kind) {
                        if (next.has(layers.popup.id)) {
                            layers.popup.popup.setContent(popupContent(kind, next.get(layers.popup.id)));
                        } else {
                            closePopup();
                        }
                    }
                }

                function drawOutlook(features) {
                    const group = layers.groups.outlook;

                    group.clearLayers();

                    features.forEach((feature) => {
                        const color = shared.safeColor(feature.color);

                        group.addLayer(L.geoJSON(feature.geometry, {
                            pane: PANES.outlook.name,
                            interactive: false,
                            style: { color: color, weight: 1, opacity: 0.8, fillColor: color, fillOpacity: 0.3 },
                        }));
                    });
                }

                function clearOutlook() {
                    layers.groups.outlook.clearLayers();
                    layers.outlookDay = null;
                    ui.status.textContent = '';
                }

                // --- Asking the server ---

                async function refreshWatches() {
                    if (! layers.choices.watches) { return; }

                    const request = ++layers.requests.watches;
                    const view = map.getBounds();
                    const box = shared.clampBounds({
                        south: view.getSouth(), west: view.getWest(), north: view.getNorth(), east: view.getEast(),
                    });
                    let result = null;

                    try {
                        result = await $wire.liveWarnings(box.bounds, layers.watchesKind);
                    } catch (error) {
                        result = null;
                    }

                    if (isStale('watches', request)) { return; }

                    try {
                        if (! result || result.status !== 'ok' || ! Array.isArray(result.events)) { throw new Error('unavailable'); }

                        drawEntries('watches', result.events.filter(shared.isEvent), (entry) => ({
                            color: shared.safeColor(entry.color), weight: 2, opacity: 0.9, fillOpacity: 0.1,
                        }));
                        setNotice('watches', '');
                    } catch (error) {
                        // Keep what is drawn; say it may be out of date.
                        setNotice('watches', MESSAGES.watches);
                    }
                }

                async function refreshOutlook() {
                    if (! layers.choices.outlook) { return; }

                    const request = ++layers.requests.outlook;
                    const day = layers.choices.day;
                    let result = null;

                    try {
                        result = await $wire.spcOutlook(day);
                    } catch (error) {
                        result = null;
                    }

                    if (isStale('outlook', request)) { return; }

                    try {
                        if (! result || result.available !== true || ! Array.isArray(result.features)) { throw new Error('unavailable'); }

                        const features = result.features.filter(isOutlookFeature);

                        drawOutlook(features);
                        layers.outlookDay = day;
                        ui.status.textContent = outlookStatus(layers.formatter, day, result.issued_at, features.length);
                        setNotice('outlook', '');
                    } catch (error) {
                        // Never "no storms": another day's areas go, this day's last good ones stay.
                        if (layers.outlookDay !== day) { clearOutlook(); }

                        setNotice('outlook', outlookUnavailable(day));
                    }
                }

                async function refreshDiscussions() {
                    if (! layers.choices.discussions) { return; }

                    const request = ++layers.requests.discussions;
                    let result = null;

                    try {
                        result = await $wire.mesoscaleDiscussions();
                    } catch (error) {
                        result = null;
                    }

                    if (isStale('discussions', request)) { return; }

                    try {
                        if (! result || result.status !== 'ok' || ! Array.isArray(result.discussions)) { throw new Error('unavailable'); }

                        drawEntries('discussions', result.discussions.filter(isDiscussion), () => ({
                            color: DISCUSSION_COLOR, weight: 3, opacity: 0.9, dashArray: '6 4', fill: false,
                        }));
                        setNotice('discussions', '');
                    } catch (error) {
                        setNotice('discussions', MESSAGES.discussions);
                    }
                }

                const REFRESH = { watches: refreshWatches, outlook: refreshOutlook, discussions: refreshDiscussions };

                // --- Turning layers on and off ---

                function setLayer(kind, on) {
                    layers.choices[kind] = on;
                    ui.toggles[kind].checked = on;

                    if (kind === 'outlook') { ui.options.hidden = ! on; }

                    if (on) {
                        layers.groups[kind].addTo(map);
                        REFRESH[kind]();

                        return;
                    }

                    layers.requests[kind]++;
                    layers.groups[kind].clearLayers();
                    layers.groups[kind].remove();
                    setNotice(kind, '');

                    if (kind === 'outlook') {
                        clearOutlook();
                    } else {
                        layers.entries[kind] = new Map();

                        if (layers.popup && layers.popup.kind === kind) { closePopup(); }
                    }
                }

                // --- Wiring ---

                ui.day.value = String(layers.choices.day);

                KINDS.forEach((kind) => {
                    listen(ui.toggles[kind], 'change', () => {
                        setLayer(kind, ui.toggles[kind].checked);
                        storeChoices(layers.choices);
                    });
                });

                listen(ui.day, 'change', () => {
                    const day = Number(ui.day.value);

                    if (! days.includes(day)) { return; }

                    layers.choices.day = day;
                    storeChoices(layers.choices);
                    setNotice('outlook', '');
                    refreshOutlook();
                });

                onMap('moveend', () => {
                    if (! layers.choices.watches) { return; }

                    clearTimeout(layers.moveTimer);
                    layers.moveTimer = setTimeout(refreshWatches, MOVE_DEBOUNCE_MS);
                });

                listen(document, 'visibilitychange', () => {
                    if (! document.hidden) { KINDS.forEach((kind) => REFRESH[kind]()); }
                });

                layers.teardown = () => {
                    clearTimeout(layers.moveTimer);
                    KINDS.forEach((kind) => { layers.requests[kind]++; });
                    closePopup();
                    layers.listeners.forEach((remove) => remove());
                    layers.listeners = [];
                    KINDS.forEach((kind) => {
                        layers.groups[kind].clearLayers();
                        layers.groups[kind].remove();
                    });
                };

                state.timers.push(setInterval(() => {
                    if (! document.hidden) { refreshWatches(); }
                }, WATCHES_REFRESH_MS));
                state.timers.push(setInterval(() => {
                    if (! document.hidden) {
                        refreshOutlook();
                        refreshDiscussions();
                    }
                }, SPC_REFRESH_MS));

                KINDS.forEach((kind) => setLayer(kind, layers.choices[kind]));
            }

            function destroy(state) {
                if (state.spcLayers && state.spcLayers.teardown) { state.spcLayers.teardown(); }

                state.spcLayers = null;
            }

            return {
                init: init,
                destroy: destroy,
                // Pure helpers, exposed for checks outside the page.
                helpers: {
                    startingChoices: startingChoices, outlookStatus: outlookStatus, outlookUnavailable: outlookUnavailable,
                    isOutlookFeature: isOutlookFeature, isDiscussion: isDiscussion, noticeText: noticeText, discussionContent: discussionContent,
                },
            };
        })();
    }
</script>
