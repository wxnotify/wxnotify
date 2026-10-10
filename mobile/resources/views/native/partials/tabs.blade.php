@use('App\Icons\Ios')
@use('App\Icons\Android')

<native:bottom-nav>
    <native:bottom-nav-item id="alerts" label="Alerts" url="/" icon="notifications" :ios-icon="Ios::Bell" :android-icon="Android::Notifications" :active="$active === 'alerts'"></native:bottom-nav-item>
    <native:bottom-nav-item id="map" label="Map" url="/map" icon="map" :ios-icon="Ios::Map" :android-icon="Android::Map" :active="$active === 'map'"></native:bottom-nav-item>
    <native:bottom-nav-item id="settings" label="Settings" url="/settings" icon="settings" :ios-icon="Ios::Gearshape" :android-icon="Android::Settings" :active="$active === 'settings'"></native:bottom-nav-item>
</native:bottom-nav>
