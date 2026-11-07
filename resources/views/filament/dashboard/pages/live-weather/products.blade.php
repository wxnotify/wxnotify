@php
    $timezone = $this->getTimezone();
@endphp

<x-filament-panels::page>
    <style>
        @keyframes wx-live-dot-pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.25; }
        }

        .wx-live-dot-pulse {
            animation: wx-live-dot-pulse 1.8s ease-in-out infinite;
        }

        @media (prefers-reduced-motion: reduce) {
            .wx-live-dot-pulse {
                animation: none;
            }
        }
    </style>

    <div
        data-live-products
        x-effect="if (! scrolled && $wire.pending > 0) { $wire.pending = 0; }"
        x-data="wxLiveProducts"
    >
        <div class="mb-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2" data-products-toolbar>
            <div class="flex flex-wrap gap-2" data-category-chips>
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

            <div class="ml-auto flex flex-wrap items-center justify-end gap-2" data-live-controls>
                <span
                    role="status"
                    aria-live="polite"
                    data-connection-status
                    class="inline-flex min-h-10 items-center gap-1.5 rounded-full px-3 text-xs font-medium ring-1 ring-inset"
                    x-bind:class="{
                        'bg-green-50 text-green-800 ring-green-600/30 dark:bg-green-500/10 dark:text-green-300 dark:ring-green-400/30': ! paused && status === 'live',
                        'bg-amber-50 text-amber-800 ring-amber-600/30 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/30': ! paused && status === 'connecting',
                        'bg-gray-100 text-gray-700 ring-gray-500/30 dark:bg-white/10 dark:text-gray-300 dark:ring-white/20': paused || status === 'offline',
                    }"
                >
                    <span aria-hidden="true" data-live-dot class="size-2 rounded-full bg-current" x-bind:class="{ 'wx-live-dot-pulse': ! paused && status === 'live' }"></span>
                    <span x-text="paused ? 'Paused' : label">Offline: refreshing every minute</span>
                    <span aria-hidden="true" data-paused-count x-show="paused && missed > 0" x-cloak x-text="'· ' + missed + ' new'"></span>
                </span>
                <button
                    type="button"
                    data-pause-toggle
                    aria-label="Pause live updates"
                    x-bind:aria-label="paused ? 'Resume live updates' : 'Pause live updates'"
                    x-on:click="paused ? resume() : pause()"
                    class="inline-flex min-h-10 items-center gap-1.5 rounded-lg bg-white px-3 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/20 dark:hover:bg-white/5"
                >
                    <span aria-hidden="true" x-show="! paused"><x-filament::icon icon="heroicon-m-pause" class="size-4" /></span>
                    <span aria-hidden="true" x-show="paused" x-cloak><x-filament::icon icon="heroicon-m-play" class="size-4" /></span>
                    <span x-text="paused ? 'Resume' : 'Pause'">Pause</span>
                </button>
            </div>
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
                    @php
                        $linked = $this->isLinkable($row['view_url']);
                    @endphp
                    <li wire:key="product-{{ $row['id'] }}" @class(['relative flex flex-wrap items-start justify-between gap-x-4 gap-y-1 px-4 py-3', 'hover:bg-gray-50 dark:hover:bg-white/5' => $linked]) data-product-row>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-950 dark:text-white">
                                @if ($linked)
                                    <a href="{{ $row['view_url'] }}" target="_blank" rel="noopener" class="outline-none after:absolute after:inset-0 focus-visible:after:ring-2 focus-visible:after:ring-inset focus-visible:after:ring-primary-600 dark:focus-visible:after:ring-primary-400" data-row-link>{{ $row['name'] }}</a>
                                @else
                                    {{ $row['name'] }}
                                @endif
                            </p>
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
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
    @vite('resources/js/echo.js')

    <script>
        (function () {
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
                paused: false,
                missed: 0,
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
                init() {
                    this.onScroll = () => {
                        this.scrolled = window.scrollY > 160;
                        if (! this.scrolled && this.$wire.pending > 0) { this.$wire.pending = 0; }
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
                    this.paused = true;
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
                toTop() { window.scrollTo({ top: 0, behavior: 'smooth' }); this.$wire.pending = 0; },
                newestId() {
                    return this.$wire.rows.reduce((max, row) => Math.max(max, Number(row.id) || 0), 0);
                },
                /*
                 * Paused freezes the list: no fetch of any kind runs (gapFill, the debounce, the
                 * retries and the poll all stand still) and events are only counted. Resume makes
                 * one catch-up gapFill and hands the polling decision back to the status logic.
                 */
                pause() {
                    if (this.paused) { return; }
                    this.paused = true;
                    this.missed = 0;
                    this.latestEvent = 0;
                    this.attempts = 0;
                    clearTimeout(this.eventTimer);
                    clearTimeout(this.retryTimer);
                    this.eventTimer = null;
                    this.retryTimer = null;
                    this.stopPolling();
                },
                resume() {
                    if (! this.paused) { return; }
                    this.paused = false;
                    this.missed = 0;
                    Promise.resolve(this.gapFill()).catch(() => {});
                    this.setStatus(this.status);
                },
                gapFill() {
                    if (this.paused) { return Promise.resolve([]); }
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
                    if (this.paused) { this.missed++; return; }
                    this.latestEvent = Math.max(this.latestEvent, id);
                    this.attempts = 0;
                    clearTimeout(this.retryTimer);
                    clearTimeout(this.eventTimer);
                    this.eventTimer = setTimeout(() => this.resolveEvents(), 1000);
                },
                async resolveEvents() {
                    this.eventTimer = null;
                    if (this.paused) { return; }
                    const target = this.latestEvent;
                    if (target === 0) { return; }
                    try { await this.gapFill(); } catch (error) {}
                    if (this.paused) { return; }
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
                    if (this.paused || this.pollTimer !== null) { return; }
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
