{{-- Un-prefixed on purpose: native:validate does not know <webview> or <pressable>. --}}

@include('native.partials.tabs', ['active' => 'map'])

<native:column class="w-full h-full bg-theme-background">
    @if ($offline)
        <native:column class="w-full p-4 gap-1 bg-theme-surface">
            <native:text class="text-base text-theme-on-background">The map needs a connection</native:text>
        </native:column>
    @endif

    @if ($error)
        <native:column class="w-full p-3 gap-2 bg-theme-surface">
            <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
            <native:button label="Try again" variant="secondary" @tap="refresh" ref="retry-map" class="w-full" />
        </native:column>
    @endif

    @if ($pageUrl !== null)
        <webview :src="$pageUrl" php="true" class="w-full flex-1" />
    @endif
</native:column>
