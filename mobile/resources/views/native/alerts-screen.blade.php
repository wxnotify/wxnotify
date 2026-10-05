@use('App\Icons\Ios')
@use('App\Icons\Android')

<native:top-bar title="Alerts" display-mode="large"></native:top-bar>

<native:bottom-nav>
    <native:bottom-nav-item id="alerts" label="Alerts" url="/" icon="notifications" :ios-icon="Ios::Bell" :android-icon="Android::Notifications" active="true"></native:bottom-nav-item>
    <native:bottom-nav-item id="account" label="Account" url="/account" icon="person" :ios-icon="Ios::Person" :android-icon="Android::Person"></native:bottom-nav-item>
</native:bottom-nav>

<native:column class="w-full h-full bg-theme-background">
    @if ($offline)
        <native:row class="w-full p-3 bg-theme-surface-variant">
            <native:text class="text-sm text-theme-on-surface-variant">{{ $syncedAt === null ? "You're offline." : "You're offline. Showing alerts from {$this->syncedLabel()}." }}</native:text>
        </native:row>
    @endif

    @if ($error)
        <native:row class="w-full p-3 bg-theme-surface-variant">
            <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
        </native:row>
    @endif

    {{-- Un-prefixed on purpose: native:validate's element list predates refreshable and pressable. --}}
    <refreshable @refresh="refresh">
        @forelse ($alerts as $alert)
            <pressable @tap="open({{ $alert['id'] }})" key="alert-{{ $alert['id'] }}" class="w-full">
                <native:column class="w-full p-4 gap-1">
                    <native:text class="text-base font-semibold text-theme-on-background">{{ $alert['subject'] }}</native:text>
                    <native:text class="text-sm text-theme-on-surface-variant">{{ $this->subtitle($alert) }}</native:text>
                    <native:text class="text-sm text-theme-on-surface-variant">{{ $this->channels($alert) }}</native:text>
                    @if ($alert['has_failure'])
                        <native:text class="text-sm text-theme-destructive">Delivery problem</native:text>
                    @endif
                </native:column>
            </pressable>
            <native:divider></native:divider>
        @empty
            <native:column class="w-full p-6 items-center gap-2">
                <native:text class="text-lg font-semibold text-theme-on-background">No alerts yet</native:text>
                <native:text class="text-sm text-theme-on-surface-variant text-center">Alerts you receive show up here once one of your rules matches.</native:text>
            </native:column>
        @endforelse

        @if ($nextCursor !== null)
            <native:button label="Load more" variant="secondary" @tap="loadMore" class="m-4" />
        @endif
    </refreshable>
</native:column>
