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
                    <li wire:key="product-{{ $row['id'] }}" class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 px-4 py-3" data-product-row>
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
                label: LABELS.offline,
                pollTimer: null,
                echoChannel: null,
                init() {
                    this.onScroll = () => {
                        this.scrolled = window.scrollY > 160;
                        if (! this.scrolled && this.$wire.pending > 0) { this.$wire.pending = 0; }
                    };
                    window.addEventListener('scroll', this.onScroll, { passive: true });

                    this.onEchoReady = () => this.connect();
                    window.addEventListener('wx-echo-ready', this.onEchoReady);
                    this.setStatus('offline');
                    this.connect();
                },
                destroy() {
                    window.removeEventListener('scroll', this.onScroll);
                    window.removeEventListener('wx-echo-ready', this.onEchoReady);
                    this.stopPolling();
                    try {
                        if (this.pusher && this.onState) { this.pusher.connection.unbind('state_change', this.onState); }
                        if (window.Echo) { window.Echo.leave('live-products'); }
                    } catch (error) {}
                },
                toTop() { window.scrollTo({ top: 0, behavior: 'smooth' }); this.$wire.pending = 0; },
                newestId() {
                    return this.$wire.rows.reduce((max, row) => Math.max(max, Number(row.id) || 0), 0);
                },
                gapFill() {
                    return this.$wire.fetchSince(this.newestId());
                },
                setStatus(status) {
                    const wasLive = this.status === 'live';
                    this.status = status;
                    this.label = LABELS[status];

                    if (status === 'live') {
                        this.stopPolling();
                        if (! wasLive) { this.gapFill(); }
                        return;
                    }

                    this.startPolling();
                },
                startPolling() {
                    if (this.pollTimer !== null) { return; }
                    this.pollTimer = setInterval(() => this.gapFill(), 60000);
                },
                stopPolling() {
                    if (this.pollTimer !== null) { clearInterval(this.pollTimer); this.pollTimer = null; }
                },
                connect() {
                    if (this.echoChannel !== null) { return; }

                    try {
                        if (! window.Echo) { return; }

                        this.pusher = window.Echo.connector.pusher;
                        this.onState = (states) => this.setStatus(statusFor(states.current));
                        this.pusher.connection.bind('state_change', this.onState);

                        this.echoChannel = window.Echo.private('live-products')
                            .listen('.ProductIngested', (event) => this.$wire.onProductIngested(event));

                        this.setStatus(statusFor(this.pusher.connection.state));
                    } catch (error) {
                        this.echoChannel = null;
                        this.setStatus('offline');
                    }
                },
            }));

            if (window.Alpine) { register(); } else { document.addEventListener('alpine:init', register); }
        })();
    </script>
</x-filament-panels::page>
