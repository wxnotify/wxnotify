<native:top-bar title="Alert rules" show-navigation-icon="true"></native:top-bar>

@include('native.partials.tabs', ['active' => 'settings'])

<native:column class="w-full h-full bg-theme-background">
    @if ($offline)
        <native:row class="w-full p-3 bg-theme-surface">
            <native:text class="text-sm text-theme-on-surface-variant">{{ $loaded && count($rules) > 0 ? "You're offline. Showing your rules as last loaded." : "You're offline." }}</native:text>
        </native:row>
    @endif

    @if ($error)
        <native:column class="w-full p-3 gap-2 bg-theme-surface">
            <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
            <native:button label="Try again" variant="secondary" @tap="refresh" ref="retry-rules" class="w-full" />
        </native:column>
    @endif

    @if ($message)
        <native:row class="w-full p-3 bg-theme-surface">
            <native:text class="text-sm text-theme-destructive">{{ $message }}</native:text>
        </native:row>
    @endif

    <native:scroll-view class="w-full flex-1 bg-theme-background">
        <native:column class="w-full p-4 gap-2">
            <native:button label="Add rule" @tap="create" ref="add-rule" class="w-full" />

            @forelse ($rules as $rule)
                <native:row class="w-full items-center justify-between gap-3">
                    {{-- Un-prefixed on purpose: native:validate does not know <pressable> (a bare tag compiles the same). Handler arguments go through {{ }} so the validator skips them. --}}
                    <pressable @tap="open({{ $rule['id'] }})" ref="rule-{{ $rule['id'] }}" class="flex-1">
                        <native:column class="gap-1">
                            <native:text class="text-base font-semibold text-theme-on-background">{{ $rule['display_name'] }}</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">{{ $this->subtitle($rule) }}</native:text>
                        </native:column>
                    </pressable>
                    <native:toggle :value="$rule['is_active']" @change="setActive({{ $rule['id'] }})" ref="active-{{ $rule['id'] }}" />
                </native:row>
                <native:divider></native:divider>
            @empty
                @if ($loaded && ! $error)
                    <native:column class="w-full p-6 items-center gap-2">
                        <native:text class="text-lg font-semibold text-theme-on-background">No alert rules yet</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant text-center">A rule decides which alerts reach you, where, and on which channels. Tap Add rule to create one.</native:text>
                    </native:column>
                @endif
            @endforelse
        </native:column>
    </native:scroll-view>
</native:column>
