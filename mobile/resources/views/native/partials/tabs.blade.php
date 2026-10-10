@use('App\Icons\Ios')
@use('App\Icons\Android')

<native:bottom-nav>
    <native:bottom-nav-item id="alerts" label="Alerts" url="/" icon="notifications" :ios-icon="Ios::Bell" :android-icon="Android::Notifications" :active="$active === 'alerts'"></native:bottom-nav-item>
    <native:bottom-nav-item id="settings" label="Settings" icon="settings" :ios-icon="Ios::Gearshape" :android-icon="Android::Settings" :active="$active === 'settings'" @tap="openSettingsMenu"></native:bottom-nav-item>
</native:bottom-nav>

@if ($settingsMenuOpen)
<native:bottom-sheet :visible="true" detents="small" @dismiss="closeSettingsMenu">
    <native:column class="w-full p-4 gap-3 bg-theme-surface">
        <native:button label="Rules" variant="secondary" @tap="openRules" class="w-full" />
        <native:button label="Locations" variant="secondary" @tap="openLocations" class="w-full" />
        <native:button label="Account" variant="secondary" @tap="openAccount" class="w-full" />
    </native:column>
</native:bottom-sheet>
@endif
