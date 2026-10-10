<native:top-bar title="Alerts" display-mode="large"></native:top-bar>

@use('App\Icons\Ios')
@use('App\Icons\Android')

<native:bottom-nav>
    <native:bottom-nav-item id="alerts" label="Alerts" url="/" icon="notifications" :ios-icon="Ios::Bell" :android-icon="Android::Notifications" :active="true"></native:bottom-nav-item>
    <native:bottom-nav-item id="settings" label="Settings" icon="settings" :ios-icon="Ios::Gearshape" :android-icon="Android::Settings" @tap="openSettingsMenu"></native:bottom-nav-item>
</native:bottom-nav>

@if ($settingsMenuOpen)
<bottom-sheet :visible="true" detents="small" @dismiss="closeSettingsMenu">
    <native:column class="w-full p-4 gap-3 bg-theme-surface">
        <native:button label="Rules" variant="secondary" @tap="openRules" class="w-full" />
        <native:button label="Locations" variant="secondary" @tap="openLocations" class="w-full" />
        <native:button label="Account" variant="secondary" @tap="openAccount" class="w-full" />
    </native:column>
</bottom-sheet>
@endif

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

    {{-- Un-prefixed on purpose: native:validate's element list does not know list or list-item (<native:list> renders the same tree). --}}
    <list separator on-refresh="refresh" on-end-reached="loadMore" :end-reached-buffer="5" class="w-full flex-1 bg-theme-background">
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
            <native:column class="w-full p-6 items-center gap-2">
                <native:text class="text-lg font-semibold text-theme-on-background">No alerts yet</native:text>
                <native:text class="text-sm text-theme-on-surface-variant text-center">Alerts you receive show up here once one of your rules matches.</native:text>
            </native:column>
        @endforelse
    </list>
</native:column>
