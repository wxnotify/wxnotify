@php
    $timezone = $this->getTimezone();
@endphp

<x-filament-panels::page>
    <div
        data-live-products
        x-effect="if (! scrolled && $wire.pending > 0) { $wire.pending = 0; }"
        x-data="wxLiveProducts"
    >
        <div class="mb-3 flex justify-end">
            <span
                role="status"
                aria-live="polite"
                data-connection-status
                class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium ring-1 ring-inset"
                x-bind:class="{
                    'bg-green-50 text-green-800 ring-green-600/30 dark:bg-green-500/10 dark:text-green-300 dark:ring-green-400/30': status === 'live',
                    'bg-amber-50 text-amber-800 ring-amber-600/30 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/30': status === 'connecting',
                    'bg-gray-100 text-gray-700 ring-gray-500/30 dark:bg-white/10 dark:text-gray-300 dark:ring-white/20': status === 'offline',
                }"
            >
                <span aria-hidden="true" class="size-2 rounded-full bg-current"></span>
                <span x-text="label">Offline: refreshing every minute</span>
            </span>
        </div>

        <div class="mb-4 flex flex-wrap gap-2" data-category-chips>
            @foreach ($this->getCategoryOptions() as $option)
                @php
                    $active = in_array($option['value'], $categories, true);
                    $next = $active
                        ? array_values(array_diff($categories, [$option['value']]))
                        : [...$categories, $option['value']];
                @endphp
                <button
                    type="button"
                    wire:click="setCategories(@js($next))"
                    aria-pressed="{{ $active ? 'true' : 'false' }}"
                    @class([
                        'rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset',
                        'bg-primary-600 text-white ring-primary-600' => $active,
                        'bg-white text-gray-700 ring-gray-300 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/20' => ! $active,
                    ])
                >{{ $option['label'] }}</button>
            @endforeach
        </div>

        <div
            x-show="scrolled && $wire.pending > 0"
            x-cloak
            class="sticky top-20 z-[25] mb-3 flex justify-center"
        >
            <button type="button" x-on:click="toTop()" class="rounded-full bg-primary-600 px-4 py-1.5 text-sm font-medium text-white shadow-lg">
                <span x-text="$wire.pending + ($wire.pending === 1 ? ' new product' : ' new products')"></span>
            </button>
        </div>

        @if (count($rows) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">No products to show yet.</p>
        @else
            <ul class="divide-y divide-gray-200 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10" data-product-list>
                @foreach ($rows as $row)
                    <li wire:key="product-{{ $row['id'] }}" class="relative isolate flex flex-wrap items-start justify-between gap-x-4 gap-y-1 px-4 py-3" data-product-row>
                        <span aria-hidden="true" x-show="isFresh({{ (int) $row['id'] }})" x-cloak x-bind:class="fadeClass()" class="pointer-events-none absolute inset-0 -z-10 bg-primary-50 dark:bg-primary-500/10" data-fresh-tint></span>
                        <span x-show="isFresh({{ (int) $row['id'] }})" x-cloak class="pointer-events-none absolute bottom-0.5 right-2 rounded-full bg-primary-50 px-1.5 text-[10px] font-semibold leading-4 text-primary-700 ring-1 ring-inset ring-primary-600/30 dark:bg-primary-500/10 dark:text-primary-300 dark:ring-primary-400/30" data-fresh-badge>New</span>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ \Illuminate\Support\Carbon::parse($row['issued_at'])->setTimezone($timezone)->format('M j, g:i A T') }}
                                @if ($row['office'])
                                    &middot; {{ $row['office'] }}
                                @endif
                                &middot; {{ \App\Support\LiveProductCategory::from($row['category'])->label() }}
                            </p>
                            @if ($row['headline'])
                                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $row['headline'] }}</p>
                            @endif
                        </div>
                        @if ($row['view_url'] && str_starts_with($row['view_url'], 'https://'))
                            <a href="{{ $row['view_url'] }}" target="_blank" rel="noopener" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">View</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    @vite('resources/js/echo.js')

    <style>
        /* Duration matches FRESH_MS in the script below. */
        @keyframes wx-fresh-fade { from { opacity: 1; } to { opacity: 0; } }
        .wx-fresh-fade { animation: wx-fresh-fade 8s linear forwards; }
        @media (prefers-reduced-motion: reduce) { .wx-fresh-fade { animation: none; } }
    </style>

    <script>
        (function () {
            /** How long a newly arrived row stays highlighted once the viewer can see it at the top. */
            const FRESH_MS = 8000;

            const LABELS = {
                live: 'Live',
                connecting: 'Reconnecting…',
                offline: 'Offline: refreshing every minute',
            };

            /** Maps a Pusher connection state to the chip's status. */
            const statusFor = (state) => {
                if (state === 'connected') { return 'live'; }
                if (state === 'connecting' || state === 'initialized') { return 'connecting'; }
                return 'offline';
            };

            const register = () => Alpine.data('wxLiveProducts', () => ({
                scrolled: false,
                status: 'offline',
                label: LABELS.offline,
                pollTimer: null,
                echoChannel: null,
                channelFailed: false,
                socketState: 'unavailable',
                lastKick: 0,
                latestEvent: 0,
                eventTimer: null,
                retryTimer: null,
                attempts: 0,
                fresh: {},
                waiting: [],
                seen: {},
                baseline: 0,
                freshTimer: null,
                reduceMotion: false,
                init() {
                    this.reduceMotion = !! (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
                    this.$wire.rows.forEach((row) => {
                        const id = Number(row.id) || 0;
                        this.seen[id] = true;
                        this.baseline = Math.max(this.baseline, id);
                    });
                    this.$wire.$watch('rows', (rows) => this.track(rows));

                    this.onScroll = () => {
                        this.scrolled = window.scrollY > 160;
                        if (! this.scrolled && this.$wire.pending > 0) { this.$wire.pending = 0; }
                        if (! this.scrolled && this.waiting.length > 0) {
                            const ids = this.waiting;
                            this.waiting = [];
                            this.highlight(ids);
                        }
                    };
                    window.addEventListener('scroll', this.onScroll, { passive: true });

                    this.onEchoReady = () => this.connect();
                    window.addEventListener('wx-echo-ready', this.onEchoReady);
                    this.onWake = () => {
                        if (document.visibilityState === 'hidden') { return; }
                        const now = Date.now();
                        if (now - this.lastKick < 5000) { return; }
                        this.lastKick = now;
                        this.gapFill();
                    };
                    document.addEventListener('visibilitychange', this.onWake);
                    window.addEventListener('online', this.onWake);
                    this.setStatus('offline');
                    this.connect();
                },
                destroy() {
                    window.removeEventListener('scroll', this.onScroll);
                    window.removeEventListener('wx-echo-ready', this.onEchoReady);
                    document.removeEventListener('visibilitychange', this.onWake);
                    window.removeEventListener('online', this.onWake);
                    this.stopPolling();
                    clearTimeout(this.eventTimer);
                    clearTimeout(this.retryTimer);
                    clearTimeout(this.freshTimer);
                    this.unbindState();
                    try {
                        if (window.Echo) { window.Echo.leave('live-products'); }
                    } catch (error) {}
                },
                unbindState() {
                    try {
                        if (this.pusher && this.onState) { this.pusher.connection.unbind('state_change', this.onState); }
                    } catch (error) {}
                    this.onState = null;
                },
                refreshStatus() {
                    this.setStatus(this.channelFailed ? 'offline' : statusFor(this.socketState));
                },
                /*
                 * Highlighting is client-side. Rows held at init are never fresh, and neither is a row
                 * with an id at or below the newest id held at init (a category switch re-lists older
                 * rows). A new row gets FRESH_MS of highlight starting when the viewer is at the top:
                 * at once if they are, otherwise when they next return to the top (pill click or
                 * manual scroll). One timer sweeps expired ids.
                 */
                track(rows) {
                    const arrived = [];
                    (rows || []).forEach((row) => {
                        const id = Number(row.id) || 0;
                        if (id <= 0 || this.seen[id]) { return; }
                        this.seen[id] = true;
                        if (id > this.baseline) { arrived.push(id); }
                    });
                    if (arrived.length === 0) { return; }
                    if (this.scrolled) { this.waiting = this.waiting.concat(arrived); return; }
                    this.highlight(arrived);
                },
                highlight(ids) {
                    const expiry = Date.now() + FRESH_MS;
                    const next = { ...this.fresh };
                    ids.forEach((id) => { next[id] = expiry; });
                    this.fresh = next;
                    this.scheduleSweep();
                },
                scheduleSweep() {
                    clearTimeout(this.freshTimer);
                    this.freshTimer = null;
                    const expiries = Object.values(this.fresh);
                    if (expiries.length === 0) { return; }
                    this.freshTimer = setTimeout(() => this.sweep(), Math.max(0, Math.min(...expiries) - Date.now()));
                },
                sweep() {
                    const now = Date.now();
                    const kept = {};
                    Object.keys(this.fresh).forEach((id) => { if (this.fresh[id] > now) { kept[id] = this.fresh[id]; } });
                    this.fresh = kept;
                    this.scheduleSweep();
                },
                isFresh(id) { return (this.fresh[id] || 0) > 0; },
                fadeClass() { return this.reduceMotion ? 'wx-fresh-static' : 'wx-fresh-fade'; },
                toTop() { window.scrollTo({ top: 0, behavior: 'smooth' }); this.$wire.pending = 0; },
                newestId() {
                    return this.$wire.rows.reduce((max, row) => Math.max(max, Number(row.id) || 0), 0);
                },
                gapFill() {
                    return this.$wire.fetchSince(this.newestId());
                },
                /*
                 * A broadcast only says "something new exists". Instead of one server round trip
                 * (and one full re-render) per event, events are collected and, once they stop for
                 * 1 s, ONE fetchSince(newest id held) reads everything newer. That same call is
                 * the recovery for a dropped event, and a rate-limited call (which answers []
                 * without a query) is simply retried: the event stays unresolved until the page
                 * holds a row at least as new as the event, a retry runs every 5 s (at most 3
                 * tries, so an event for a product the category filter hides cannot loop), and the
                 * minute poll keeps running meanwhile even when the chip says Live.
                 */
                onEvent(event) {
                    const id = Number(event && event.id);
                    if (! Number.isInteger(id) || id <= 0) { return; }
                    this.latestEvent = Math.max(this.latestEvent, id);
                    this.attempts = 0;
                    clearTimeout(this.retryTimer);
                    clearTimeout(this.eventTimer);
                    this.eventTimer = setTimeout(() => this.resolveEvents(), 1000);
                },
                async resolveEvents() {
                    this.eventTimer = null;
                    const target = this.latestEvent;
                    if (target === 0) { return; }
                    try { await this.gapFill(); } catch (error) {}
                    if (this.latestEvent !== target) { return; }
                    this.attempts++;
                    if (this.newestId() >= target || this.attempts >= 3) { this.settle(); return; }
                    this.startPolling();
                    this.retryTimer = setTimeout(() => this.resolveEvents(), 5000);
                },
                settle() {
                    this.latestEvent = 0;
                    this.attempts = 0;
                    clearTimeout(this.retryTimer);
                    if (this.status === 'live') { this.stopPolling(); }
                },
                setStatus(status) {
                    const wasLive = this.status === 'live';
                    this.status = status;
                    this.label = LABELS[status];

                    if (status === 'live') {
                        if (this.latestEvent === 0) { this.stopPolling(); }
                        if (! wasLive) { this.gapFill(); }
                        return;
                    }

                    this.startPolling();
                },
                startPolling() {
                    if (this.pollTimer !== null) { return; }
                    this.pollTimer = setInterval(() => (this.latestEvent > 0 ? this.resolveEvents() : this.gapFill()), 60000);
                },
                stopPolling() {
                    if (this.pollTimer !== null) { clearInterval(this.pollTimer); this.pollTimer = null; }
                },
                connect() {
                    if (this.echoChannel !== null) { return; }

                    try {
                        if (! window.Echo) { return; }

                        this.pusher = window.Echo.connector.pusher;
                        this.onState = (states) => { this.socketState = states.current; this.refreshStatus(); };
                        this.pusher.connection.bind('state_change', this.onState);

                        this.echoChannel = window.Echo.private('live-products')
                            .listen('.ProductIngested', (event) => this.onEvent(event))
                            .subscribed(() => { this.channelFailed = false; this.refreshStatus(); })
                            .error(() => { this.channelFailed = true; this.refreshStatus(); });

                        this.socketState = this.pusher.connection.state;
                        this.refreshStatus();
                    } catch (error) {
                        this.unbindState();
                        this.echoChannel = null;
                        this.setStatus('offline');
                    }
                },
            }));

            if (window.Alpine) { register(); } else { document.addEventListener('alpine:init', register); }
        })();
    </script>
</x-filament-panels::page>
