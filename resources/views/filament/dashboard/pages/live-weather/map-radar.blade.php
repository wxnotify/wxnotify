{{--
    The radar for the Live map: the controls under the map and window.wxLiveMapRadar, which the
    page's initLiveWeatherMap starts once Leaflet is ready. Frame times come from the page's
    radarFrames() (the server validates the layer and site); the tiles load in the browser
    straight from NOAA's WMS. Nothing here is asked for while the page renders.
--}}
@php
    $selectClasses = 'block rounded-lg border-gray-300 bg-white py-1.5 text-sm text-gray-950 shadow-sm dark:border-white/10 dark:bg-white/5 dark:text-white';
    $buttonClasses = 'inline-flex items-center justify-center rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm font-medium text-gray-950 shadow-sm hover:bg-gray-50 disabled:opacity-50 dark:border-white/10 dark:bg-white/5 dark:text-white dark:hover:bg-white/10';
@endphp

<div wire:ignore data-radar-controls class="mt-3 space-y-3">
    <p
        data-radar-banner
        role="status"
        hidden
        class="rounded-lg bg-amber-50 px-3 py-2 text-sm text-amber-800 dark:bg-amber-500/10 dark:text-amber-300"
    ></p>

    <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm text-gray-700 dark:text-gray-300">
            <span class="mb-1 block font-medium">Radar</span>
            <select data-radar-layer class="{{ $selectClasses }}">
                @foreach ($this->getRadarLayerOptions() as $layerKey => $layerLabel)
                    <option value="{{ $layerKey }}">{{ $layerLabel }}</option>
                @endforeach
            </select>
        </label>

        <label data-radar-site-wrap hidden class="text-sm text-gray-700 dark:text-gray-300">
            <span class="mb-1 block font-medium">Radar site</span>
            <select data-radar-site class="{{ $selectClasses }}">
                <option value="">Choose a radar site</option>
                <option value="nearest">Nearest to the map centre</option>
                @foreach ($this->radarSites() as $radarSite)
                    <option value="{{ $radarSite['id'] }}">{{ $radarSite['name'] }} ({{ strtoupper($radarSite['id']) }})</option>
                @endforeach
            </select>
        </label>

        <label class="text-sm text-gray-700 dark:text-gray-300">
            <span class="mb-1 block font-medium">Speed</span>
            <select data-radar-speed class="{{ $selectClasses }}">
                <option value="slow">Slow</option>
                <option value="normal" selected>Normal</option>
                <option value="fast">Fast</option>
            </select>
        </label>

        <label class="text-sm text-gray-700 dark:text-gray-300">
            <span class="mb-1 block font-medium">Frames</span>
            <select data-radar-frames class="{{ $selectClasses }}">
                <option value="6">6</option>
                <option value="12" selected>12</option>
                <option value="24">24</option>
                <option value="36">36</option>
            </select>
        </label>
    </div>

    <div class="flex flex-wrap items-center gap-2">
        <button type="button" data-radar-step="-1" aria-label="Previous frame" class="{{ $buttonClasses }}">&lsaquo;</button>
        <button type="button" data-radar-play class="{{ $buttonClasses }} min-w-[4.5rem]">Play</button>
        <button type="button" data-radar-step="1" aria-label="Next frame" class="{{ $buttonClasses }}">&rsaquo;</button>
        <input type="range" data-radar-scrubber min="0" max="0" value="0" step="1" disabled aria-label="Radar frame time" class="min-w-[8rem] flex-1">
        <span data-radar-time class="min-w-[6rem] text-sm tabular-nums text-gray-700 dark:text-gray-300"></span>
    </div>

    <img data-radar-legend hidden alt="" class="max-w-full">
</div>

<script>
    // Defined once per page. Started by window.initLiveWeatherMap with { state, $wire, page };
    // window.destroyLiveWeatherMap calls destroy(state). Text goes in with textContent only.
    if (! window.wxLiveMapRadar) {
        window.wxLiveMapRadar = (function () {
            const REFRESH_MS = 2 * 60 * 1000;
            const STALE_MINUTES = 15;
            const FRAME_COUNTS = [6, 12, 24, 36];
            const DEFAULT_FRAME_COUNT = 12;
            const SPEEDS_MS = { slow: 1000, normal: 500, fast: 250 };
            const DEFAULT_SPEED = 'normal';
            const SHOWN_OPACITY = 0.7;
            const STORAGE_KEY = 'wxnotify.liveMap.radar';
            const SITE_LAYERS = ['site_reflectivity', 'site_velocity'];
            const NEAREST = 'nearest';
            const UNAVAILABLE = 'Radar is unavailable right now';
            const CHOOSE_SITE = 'Choose a radar site to see this layer.';

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

            function startingChoices(stored, config, siteIds) {
                const layers = Array.isArray(config.layers) ? config.layers : [];
                let site = '';

                if (siteIds.includes(stored.site)) {
                    site = stored.site;
                } else if (siteIds.includes(config.default_site)) {
                    site = config.default_site;
                }

                return {
                    layer: layers.includes(stored.layer) ? stored.layer : (layers[0] || 'composite'),
                    site: site,
                    frames: FRAME_COUNTS.includes(Number(stored.frames)) ? Number(stored.frames) : DEFAULT_FRAME_COUNT,
                    speed: Object.prototype.hasOwnProperty.call(SPEEDS_MS, stored.speed) ? stored.speed : DEFAULT_SPEED,
                };
            }

            // --- Frame selection ---

            function chosenTimes(times, count) {
                return times.slice(-count);
            }

            // Where the viewer lands after new frames arrive. -1 means "keep the frames as they are".
            // mode: 'choice' (a new layer or site), 'resize' (a new frame count), 'refresh' (the timer
            // or the tab coming back) or 'catchup' (a paused viewer acts after their frame aged out:
            // they land on the oldest frame, the one nearest where they were).
            function frameIndexAfterUpdate(mode, previousTime, wasOnNewest, playing, times) {
                const newest = times.length - 1;

                if (mode === 'choice' || wasOnNewest || previousTime === null) {
                    return newest;
                }

                const index = times.indexOf(previousTime);

                if (index >= 0) {
                    return index;
                }

                if (mode === 'resize') {
                    return newest;
                }

                if (mode === 'catchup') {
                    return 0;
                }

                return playing ? 0 : -1;
            }

            // A layer or site change stays pending until one answer has been applied, so a timer or
            // visibility refresh that overtakes it is applied as the choice it really is.
            function effectiveMode(requestedMode, choicePending) {
                return choicePending ? 'choice' : requestedMode;
            }

            // --- Time label ---

            function makeFormatter(timezone, options) {
                try {
                    return new Intl.DateTimeFormat(undefined, timezone ? Object.assign({ timeZone: timezone }, options) : options);
                } catch (error) {
                    return new Intl.DateTimeFormat(undefined, options);
                }
            }

            function timeFormatters(timezone) {
                return {
                    time: makeFormatter(timezone, { hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }),
                    dateTime: makeFormatter(timezone, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', timeZoneName: 'short' }),
                    day: makeFormatter(timezone, { year: 'numeric', month: '2-digit', day: '2-digit' }),
                };
            }

            // The frame's time, with a short date only when its local date is not the newest frame's.
            function timeLabel(formatters, iso, newestIso) {
                const date = new Date(iso);
                const newest = new Date(newestIso);

                if (isNaN(date.getTime())) { return ''; }

                const sameDay = isNaN(newest.getTime()) || formatters.day.format(date) === formatters.day.format(newest);

                return (sameDay ? formatters.time : formatters.dateTime).format(date);
            }

            // --- Banner state ---

            function isUsable(result) {
                return !! result && result.status === 'ok' && Array.isArray(result.times) && result.times.length > 0
                    && typeof result.wms_url === 'string' && typeof result.wms_layer === 'string';
            }

            function minutesOld(iso, now) {
                const time = Date.parse(iso);

                return isNaN(time) ? null : Math.floor((now - time) / 60000);
            }

            function bannerText(result, now) {
                if (! isUsable(result)) {
                    return UNAVAILABLE;
                }

                const age = minutesOld(result.times[result.times.length - 1], now);

                if (age === null) {
                    return UNAVAILABLE;
                }

                return age > STALE_MINUTES ? 'Radar is ' + age + ' minutes old' : '';
            }

            // --- NOAA WMS ---

            function legendUrl(result) {
                return result.wms_url + '?service=WMS&version=1.3.0&request=GetLegendGraphic&format=image/png&layer='
                    + encodeURIComponent(result.wms_layer);
            }

            function frameLayer(result, time) {
                return L.tileLayer.wms(result.wms_url, {
                    layers: result.wms_layer,
                    format: 'image/png',
                    transparent: true,
                    version: '1.3.0',
                    time: time,
                    opacity: 0,
                    attribution: 'Radar: NOAA/NWS',
                });
            }

            function prefersReducedMotion() {
                return !! (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
            }

            // --- The radar on one page ---

            function init(context) {
                const state = context.state;
                const $wire = context.$wire;
                const root = context.page.querySelector('[data-radar-controls]');

                if (! root) {
                    if (window.console) { console.warn('Live map: the radar controls are missing, so the radar is off.'); }

                    return;
                }

                const ui = {
                    banner: root.querySelector('[data-radar-banner]'),
                    layer: root.querySelector('[data-radar-layer]'),
                    site: root.querySelector('[data-radar-site]'),
                    siteWrap: root.querySelector('[data-radar-site-wrap]'),
                    speed: root.querySelector('[data-radar-speed]'),
                    frames: root.querySelector('[data-radar-frames]'),
                    play: root.querySelector('[data-radar-play]'),
                    back: root.querySelector('[data-radar-step="-1"]'),
                    forward: root.querySelector('[data-radar-step="1"]'),
                    scrubber: root.querySelector('[data-radar-scrubber]'),
                    time: root.querySelector('[data-radar-time]'),
                    legend: root.querySelector('[data-radar-legend]'),
                };
                const config = (state.config && state.config.radar) || {};
                const siteIds = Array.from(ui.site.options).map((option) => option.value)
                    .filter((value) => value !== '' && value !== NEAREST);

                const radar = state.radar = {
                    choices: startingChoices(readStoredChoices(), config, siteIds),
                    formatters: timeFormatters(config.timezone),
                    result: null,
                    pendingResult: null,
                    choicePending: false,
                    times: [],
                    frameLayers: new Map(),
                    index: -1,
                    playing: ! prefersReducedMotion(),
                    loopTimer: null,
                    request: 0,
                    siteRequest: 0,
                    listeners: [],
                };

                function listen(target, type, handler) {
                    target.addEventListener(type, handler);
                    radar.listeners.push(() => target.removeEventListener(type, handler));
                }

                function isSiteLayer() {
                    return SITE_LAYERS.includes(radar.choices.layer);
                }

                function saveChoices() {
                    storeChoices(radar.choices);
                }

                function setBanner(text) {
                    ui.banner.textContent = text;
                    ui.banner.hidden = text === '';
                }

                function showChoices() {
                    ui.layer.value = radar.choices.layer;
                    ui.site.value = radar.choices.site;
                    ui.speed.value = radar.choices.speed;
                    ui.frames.value = String(radar.choices.frames);
                    ui.siteWrap.hidden = ! isSiteLayer();
                }

                function showPlayState() {
                    ui.play.textContent = radar.playing ? 'Pause' : 'Play';
                }

                function showLegend(result) {
                    if (! isUsable(result)) {
                        ui.legend.hidden = true;
                        ui.legend.removeAttribute('src');

                        return;
                    }

                    const url = legendUrl(result);

                    if (ui.legend.getAttribute('src') !== url) { ui.legend.src = url; }

                    ui.legend.alt = 'Legend for ' + ui.layer.options[ui.layer.selectedIndex].textContent;
                    ui.legend.hidden = false;
                }

                // --- Frames on the map ---

                function frameKey(result, time) {
                    return result.wms_url + '|' + result.wms_layer + '|' + time;
                }

                function syncFrameLayers(result, times) {
                    const wanted = new Map();

                    times.forEach((time) => {
                        const key = frameKey(result, time);
                        let layer = radar.frameLayers.get(key);

                        if (! layer) {
                            layer = frameLayer(result, time);
                            layer.addTo(state.map);
                        }

                        layer.setOpacity(0);
                        wanted.set(key, layer);
                    });

                    radar.frameLayers.forEach((layer, key) => {
                        if (! wanted.has(key)) { layer.remove(); }
                    });

                    radar.frameLayers = wanted;
                }

                function clearFrames() {
                    radar.frameLayers.forEach((layer) => layer.remove());
                    radar.frameLayers = new Map();
                    radar.result = null;
                    radar.pendingResult = null;
                    radar.times = [];
                    radar.index = -1;
                    ui.scrubber.max = '0';
                    ui.scrubber.value = '0';
                    ui.scrubber.disabled = true;
                    ui.time.textContent = '';
                    showLegend(null);
                }

                function showFrame(index) {
                    const layers = Array.from(radar.frameLayers.values());

                    if (layers.length === 0) { return; }

                    const next = ((index % layers.length) + layers.length) % layers.length;
                    const label = timeLabel(radar.formatters, radar.times[next], radar.times[radar.times.length - 1]);

                    if (layers[radar.index]) { layers[radar.index].setOpacity(0); }

                    layers[next].setOpacity(SHOWN_OPACITY);
                    radar.index = next;
                    ui.scrubber.value = String(next);
                    ui.scrubber.setAttribute('aria-valuetext', label);
                    ui.time.textContent = label;
                }

                function applyResult(result, mode) {
                    const times = chosenTimes(result.times, radar.choices.frames);
                    const previousTime = radar.index >= 0 ? (radar.times[radar.index] || null) : null;
                    const wasOnNewest = radar.index === radar.times.length - 1;
                    const index = frameIndexAfterUpdate(mode, previousTime, wasOnNewest, radar.playing, times);

                    if (index < 0) {
                        // A paused viewer's frame aged out: they stay put; their next action catches up.
                        radar.pendingResult = result;

                        return;
                    }

                    syncFrameLayers(result, times);
                    radar.result = result;
                    radar.pendingResult = null;
                    radar.times = times;
                    radar.index = -1;
                    ui.scrubber.max = String(Math.max(times.length - 1, 0));
                    ui.scrubber.disabled = times.length < 2;
                    showFrame(index);
                    showLegend(result);
                }

                // --- Asking the server for frame times ---

                async function refresh(mode) {
                    const request = ++radar.request;

                    if (mode === 'choice') { radar.choicePending = true; }

                    if (isSiteLayer() && radar.choices.site === '') {
                        radar.choicePending = false;
                        clearFrames();
                        setBanner(CHOOSE_SITE);

                        return;
                    }

                    let result = null;

                    try {
                        result = await $wire.radarFrames(radar.choices.layer, isSiteLayer() ? radar.choices.site : null);
                    } catch (error) {
                        result = null;
                    }

                    if (state.destroyed || request !== radar.request || ! state.map) { return; }

                    const applyMode = effectiveMode(mode, radar.choicePending);
                    radar.choicePending = false;

                    if (! isUsable(result)) {
                        if (applyMode === 'choice') { clearFrames(); }
                        setBanner(UNAVAILABLE);

                        return;
                    }

                    applyResult(result, applyMode);
                    // From the frames on the map, which a paused viewer may still be looking at.
                    setBanner(bannerText(radar.result, Date.now()));
                }

                // A paused viewer whose frame aged out moves to the fresh frames when they act.
                function catchUp() {
                    if (! radar.pendingResult) { return; }

                    applyResult(radar.pendingResult, 'catchup');
                    setBanner(bannerText(radar.result, Date.now()));
                }

                // --- The loop ---

                function stopLoop() {
                    if (radar.loopTimer) { clearInterval(radar.loopTimer); radar.loopTimer = null; }
                }

                function startLoop() {
                    stopLoop();

                    if (radar.playing && ! document.hidden) {
                        radar.loopTimer = setInterval(() => showFrame(radar.index + 1), SPEEDS_MS[radar.choices.speed]);
                    }
                }

                function setPlaying(playing) {
                    catchUp();
                    radar.playing = playing;
                    showPlayState();
                    startLoop();
                }

                function step(direction) {
                    const caughtUp = !! radar.pendingResult;

                    setPlaying(false);

                    if (! caughtUp) { showFrame(radar.index + direction); }
                }

                // --- Control handlers ---

                async function chooseNearestSite() {
                    const request = ++radar.siteRequest;
                    let site = null;

                    try {
                        const center = state.map.getCenter().wrap();
                        site = await $wire.nearestRadarSite(center.lat, center.lng);
                    } catch (error) {
                        site = null;
                    }

                    // A site picked by hand meanwhile wins over a late answer.
                    if (state.destroyed || request !== radar.siteRequest) { return; }

                    if (siteIds.includes(site)) {
                        radar.choices.site = site;
                        saveChoices();
                        refresh('choice');
                    }

                    ui.site.value = radar.choices.site;
                }

                listen(ui.layer, 'change', () => {
                    radar.choices.layer = ui.layer.value;
                    saveChoices();
                    showChoices();
                    refresh('choice');
                });

                listen(ui.site, 'change', () => {
                    if (ui.site.value === NEAREST) {
                        chooseNearestSite();

                        return;
                    }

                    radar.siteRequest++;
                    radar.choices.site = ui.site.value;
                    saveChoices();
                    refresh('choice');
                });

                listen(ui.speed, 'change', () => {
                    radar.choices.speed = Object.prototype.hasOwnProperty.call(SPEEDS_MS, ui.speed.value) ? ui.speed.value : DEFAULT_SPEED;
                    saveChoices();
                    startLoop();
                });

                listen(ui.frames, 'change', () => {
                    const count = Number(ui.frames.value);
                    radar.choices.frames = FRAME_COUNTS.includes(count) ? count : DEFAULT_FRAME_COUNT;
                    saveChoices();

                    const latest = radar.pendingResult || radar.result;

                    if (latest) {
                        applyResult(latest, 'resize');
                        setBanner(bannerText(radar.result, Date.now()));
                    }
                });

                listen(ui.play, 'click', () => setPlaying(! radar.playing));
                listen(ui.back, 'click', () => step(-1));
                listen(ui.forward, 'click', () => step(1));

                listen(ui.scrubber, 'input', () => {
                    const caughtUp = !! radar.pendingResult;

                    setPlaying(false);

                    if (! caughtUp) { showFrame(Number(ui.scrubber.value)); }
                });

                listen(document, 'visibilitychange', () => {
                    if (document.hidden) {
                        stopLoop();

                        return;
                    }

                    startLoop();
                    refresh('refresh');
                });

                radar.teardown = () => {
                    stopLoop();
                    radar.request++;
                    radar.listeners.forEach((remove) => remove());
                    radar.listeners = [];
                    radar.frameLayers.forEach((layer) => layer.remove());
                    radar.frameLayers = new Map();
                };

                showChoices();
                showPlayState();
                state.timers.push(setInterval(() => {
                    if (! document.hidden) { refresh('refresh'); }
                }, REFRESH_MS));
                refresh('choice').then(startLoop, startLoop);
            }

            function destroy(state) {
                if (state.radar && state.radar.teardown) { state.radar.teardown(); }

                state.radar = null;
            }

            return {
                init: init,
                destroy: destroy,
                // Pure helpers, exposed for checks outside the page.
                helpers: { frameIndexAfterUpdate: frameIndexAfterUpdate, effectiveMode: effectiveMode, bannerText: bannerText, timeLabel: timeLabel, timeFormatters: timeFormatters },
            };
        })();
    }
</script>
