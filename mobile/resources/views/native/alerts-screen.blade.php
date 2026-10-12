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

    {{-- Un-prefixed on purpose: native:validate's element list does not know list, list-section or list-item (<native:list> renders the same tree). --}}
    <list separator plain="true" on-refresh="refresh" on-end-reached="loadMore" :end-reached-buffer="5" class="w-full flex-1 bg-theme-background">
        <list-section header="Current watches, warnings and advisories">
            @if ($this->activeNote())
                <list-item supporting="{{ $this->activeNote() }}"></list-item>
            @endif

            @foreach ($this->activeRows() as $event)
                @if (filled($event['view_url'] ?? null))
                    <list-item
                        headline="{{ $event['event'] }}"
                        overline="{{ $this->activeOverline($event) }}"
                        supporting="{{ $this->activePlaces($event) }}"
                        @tap="openActive({{ $event['id'] }})"
                    ></list-item>
                @else
                    <list-item
                        headline="{{ $event['event'] }}"
                        overline="{{ $this->activeOverline($event) }}"
                        supporting="{{ $this->activePlaces($event) }}"
                    ></list-item>
                @endif
            @endforeach

            @if ($this->activeEmptyLine())
                <list-item headline="{{ $this->activeEmptyLine() }}"></list-item>
            @endif

            @if ($this->activeHiddenCount() > 0)
                <list-item headline="Show all ({{ $this->activeHiddenCount() }})" @tap="toggleActive"></list-item>
            @elseif ($this->canShowFewerActive())
                <list-item headline="Show fewer" @tap="toggleActive"></list-item>
            @endif
        </list-section>

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
