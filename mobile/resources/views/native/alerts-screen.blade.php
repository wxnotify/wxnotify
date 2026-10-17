<native:top-bar title="Alerts" display-mode="large"></native:top-bar>

@include('native.partials.tabs', ['active' => 'alerts'])

<native:column class="w-full h-full bg-theme-background">
    @if ($offline)
        <native:row class="w-full p-3 bg-theme-surface">
            <native:text class="text-sm text-theme-on-surface-variant">{{ $syncedAt === null ? "You're offline." : "You're offline. Showing alerts from {$this->syncedLabel()}." }}</native:text>
        </native:row>
    @endif

    @if ($error)
        <native:row class="w-full p-3 bg-theme-surface">
            <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
        </native:row>
    @endif

    {{-- The current watches, warnings and advisories sit in a bordered panel ABOVE the list: a native list section cannot be bordered. Un-prefixed <pressable> on purpose: native:validate does not know it (a bare tag compiles the same). --}}
    <native:column class="w-full px-3 pt-3">
        <native:column class="w-full p-3 gap-2 rounded-xl border-2 border-theme-accent bg-theme-surface">
            <native:text class="text-sm font-semibold text-theme-on-surface-variant">Current watches, warnings and advisories</native:text>

            @if ($this->activeNote())
                <native:text class="text-sm text-theme-on-surface-variant">{{ $this->activeNote() }}</native:text>
            @endif

            @if ($showAllActive && $this->canShowFewerActive())
                <native:scroll-view class="w-full h-[300]">
                    <native:column class="w-full gap-2">
                        @include('native.partials.active-alert-rows', ['screen' => $this])
                    </native:column>
                </native:scroll-view>
            @else
                <native:column class="w-full gap-2">
                    @include('native.partials.active-alert-rows', ['screen' => $this])
                </native:column>
            @endif

            @if ($this->activeEmptyLine())
                <native:text class="text-base text-theme-on-background">{{ $this->activeEmptyLine() }}</native:text>
            @endif

            @if ($this->activeHiddenCount() > 0)
                <pressable @tap="toggleActive" ref="toggle-active" class="w-full">
                    <native:text class="text-sm font-semibold text-theme-primary py-1">Show all ({{ $this->activeHiddenCount() }})</native:text>
                </pressable>
            @elseif ($this->canShowFewerActive())
                <pressable @tap="toggleActive" ref="toggle-active" class="w-full">
                    <native:text class="text-sm font-semibold text-theme-primary py-1">Show fewer</native:text>
                </pressable>
            @endif
        </native:column>
    </native:column>

    {{-- Un-prefixed on purpose: native:validate's element list does not know list, list-section or list-item (<native:list> renders the same tree). --}}
    <list separator plain="true" on-refresh="refresh" on-end-reached="loadMore" :end-reached-buffer="5" class="w-full flex-1 bg-theme-background">
        <list-section header="Alerts sent to you">
            @forelse ($alerts as $alert)
                {{-- Rows must NOT be keyed (no native:key): Android's LazyColumn keeps the previously first keyed row anchored when items are inserted above it, hiding newly arrived alerts after a refresh. refresh() replaces the whole list and the rows hold no state. --}}
                <list-item
                    headline="{{ $alert['subject'] }}"
                    overline="{{ $this->subtitle($alert) }}"
                    :supporting="$this->supporting($alert)"
                    :trailing-badges="$this->failureBadges($alert)"
                    @tap="open({{ $alert['id'] }})"
                    @longPress="openDetail({{ $alert['id'] }})"
                ></list-item>
            @empty
                <list-item headline="No alerts yet" supporting="Alerts you receive show up here once one of your rules matches."></list-item>
            @endforelse
        </list-section>
    </list>
</native:column>
