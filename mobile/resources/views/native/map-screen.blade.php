{{-- Un-prefixed on purpose: native:validate does not know <webview> or <pressable>. Handler arguments go through {{ }} so the validator skips them. --}}

<native:top-bar title="Map" display-mode="large"></native:top-bar>

@include('native.partials.tabs', ['active' => 'map'])

<native:column class="w-full h-full bg-theme-background">
    @if ($offline)
        <native:column class="w-full p-4 gap-1 bg-theme-surface">
            <native:text class="text-base text-theme-on-background">The map needs a connection</native:text>
            @if ($loaded)
                <native:text class="text-sm text-theme-on-surface-variant">You're offline. Showing what was near you when this was last loaded.</native:text>
            @endif
        </native:column>
    @endif

    @if ($error)
        <native:column class="w-full p-3 gap-2 bg-theme-surface">
            <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
            <native:button label="Try again" variant="secondary" @tap="refresh" ref="retry-map" class="w-full" />
        </native:column>
    @endif

    @if ($html !== null)
        <webview :html="$html" javascript="true" dom-storage="true" class="w-full h-80" />
    @endif

    <native:scroll-view class="w-full flex-1 bg-theme-background">
        <native:column class="w-full p-4 gap-2">
            <native:row class="w-full justify-between items-center">
                <native:text class="text-lg font-bold text-theme-on-background">Near you</native:text>
                <native:button label="Refresh" variant="secondary" @tap="refresh" ref="refresh-map" />
            </native:row>

            @foreach (($snapshot['warnings'] ?? []) as $index => $warning)
                @if (filled($warning['view_url'] ?? null))
                    <pressable @tap="openWarning({{ $index }})" ref="warning-{{ $index }}" class="w-full">
                        <native:column class="w-full gap-1 p-2">
                            <native:text class="text-base font-semibold text-theme-on-background">{{ $warning['event'] }}</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">{{ $this->warningSubtitle($warning) }}</native:text>
                        </native:column>
                    </pressable>
                @else
                    <native:column class="w-full gap-1 p-2">
                        <native:text class="text-base font-semibold text-theme-on-background">{{ $warning['event'] }}</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant">{{ $this->warningSubtitle($warning) }}</native:text>
                    </native:column>
                @endif
                <native:divider></native:divider>
            @endforeach

            @foreach (($snapshot['places'] ?? []) as $place)
                <pressable @tap="openPlace({{ $place['id'] }})" ref="place-{{ $place['id'] }}" class="w-full">
                    <native:column class="w-full gap-1 p-2">
                        <native:text class="text-base font-semibold text-theme-on-background">{{ $place['label'] }}</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant">{{ $this->placeSubtitle($place) }}</native:text>
                    </native:column>
                </pressable>
                <native:divider></native:divider>
            @endforeach

            @if ($this->hasNothingToShow())
                <native:text class="text-sm text-theme-on-surface-variant">No places to show yet. Save a location or turn on location sharing in Account.</native:text>
            @endif

            @if ($loaded && ($snapshot['phone'] ?? null) === null)
                <native:text class="text-sm text-theme-on-surface-variant">Turn on location sharing in Account to center the map on this phone.</native:text>
            @endif
        </native:column>
    </native:scroll-view>
</native:column>
