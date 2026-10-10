<native:top-bar title="Locations" show-navigation-icon="true"></native:top-bar>

@include('native.partials.tabs', ['active' => 'settings'])

<native:column class="w-full h-full bg-theme-background">
    @if ($offline)
        <native:row class="w-full p-3 bg-theme-surface">
            <native:text class="text-sm text-theme-on-surface-variant">{{ $loaded && count($locations) > 0 ? "You're offline. Showing your locations as last loaded." : "You're offline." }}</native:text>
        </native:row>
    @endif

    @if ($error)
        <native:column class="w-full p-3 gap-2 bg-theme-surface">
            <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
            <native:button label="Try again" variant="secondary" @tap="refresh" ref="retry-locations" class="w-full" />
        </native:column>
    @endif

    @if ($message)
        <native:row class="w-full p-3 bg-theme-surface">
            <native:text class="text-sm text-theme-destructive">{{ $message }}</native:text>
        </native:row>
    @endif

    <native:scroll-view class="w-full flex-1 bg-theme-background">
        <native:column class="w-full p-4 gap-2">
            <native:button label="Add location" @tap="create" ref="add-location" class="w-full" />

            @forelse ($locations as $location)
                {{-- Un-prefixed on purpose: native:validate does not know <pressable> (a bare tag compiles the same). The handler argument goes through {{ }} so the validator skips it. --}}
                <pressable @tap="open({{ $location['id'] }})" ref="location-{{ $location['id'] }}" class="w-full">
                    <native:column class="w-full gap-1 p-2">
                        <native:text class="text-base font-semibold text-theme-on-background">{{ $location['label'] }}</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant">{{ $this->subtitle($location) }}</native:text>
                    </native:column>
                </pressable>
                <native:divider></native:divider>
            @empty
                @if ($loaded && ! $error)
                    <native:column class="w-full p-6 items-center gap-2">
                        <native:text class="text-lg font-semibold text-theme-on-background">No locations yet</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant text-center">Locations are the places your alerts watch. Tap Add location to save one.</native:text>
                    </native:column>
                @endif
            @endforelse
        </native:column>
    </native:scroll-view>
</native:column>
