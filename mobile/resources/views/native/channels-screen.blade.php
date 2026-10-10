<native:top-bar title="Channels" show-navigation-icon="true"></native:top-bar>

@include('native.partials.tabs', ['active' => 'settings'])

<native:column class="w-full h-full bg-theme-background">
    @if ($offline)
        <native:row class="w-full p-3 bg-theme-surface">
            <native:text class="text-sm text-theme-on-surface-variant">{{ $loaded && count($channels) > 0 ? "You're offline. Showing your channels as last loaded." : "You're offline." }}</native:text>
        </native:row>
    @endif

    @if ($error)
        <native:column class="w-full p-3 gap-2 bg-theme-surface">
            <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
            <native:button label="Try again" variant="secondary" @tap="refresh" ref="retry-channels" class="w-full" />
        </native:column>
    @endif

    <native:scroll-view class="w-full flex-1 bg-theme-background">
        <native:column class="w-full p-4 gap-2">
            <native:button label="Add channel" @tap="create" ref="add-channel" class="w-full" />

            @forelse ($channels as $channel)
                @if ($channel['managed_by_app'])
                    <native:column class="w-full gap-1 p-2">
                        <native:text class="text-base font-semibold text-theme-on-background">{{ $channel['label'] }}</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant">{{ $this->subtitle($channel) }}</native:text>
                    </native:column>
                @else
                    {{-- Un-prefixed on purpose: native:validate does not know <pressable> (a bare tag compiles the same). The handler argument goes through {{ }} so the validator skips it. --}}
                    <pressable @tap="open({{ $channel['id'] }})" ref="channel-{{ $channel['id'] }}" class="w-full">
                        <native:column class="w-full gap-1 p-2">
                            <native:text class="text-base font-semibold text-theme-on-background">{{ $channel['label'] }}</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">{{ $this->subtitle($channel) }}</native:text>
                        </native:column>
                    </pressable>
                @endif
                <native:divider></native:divider>
            @empty
                @if ($loaded && ! $error)
                    <native:column class="w-full p-6 items-center gap-2">
                        <native:text class="text-lg font-semibold text-theme-on-background">No channels yet</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant text-center">Channels are where your alerts are sent. Tap Add channel to connect one.</native:text>
                    </native:column>
                @endif
            @endforelse
        </native:column>
    </native:scroll-view>
</native:column>
