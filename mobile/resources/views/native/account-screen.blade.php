@use('App\Icons\Ios')
@use('App\Icons\Android')

<native:top-bar title="Account" display-mode="large"></native:top-bar>

<native:bottom-nav>
    <native:bottom-nav-item id="alerts" label="Alerts" url="/" icon="notifications" :ios-icon="Ios::Bell" :android-icon="Android::Notifications"></native:bottom-nav-item>
    <native:bottom-nav-item id="account" label="Account" url="/account" icon="person" :ios-icon="Ios::Person" :android-icon="Android::Person" active="true"></native:bottom-nav-item>
</native:bottom-nav>

<native:column class="w-full h-full p-4 gap-4 bg-theme-background">
    @if ($profile !== null)
        <native:column class="w-full gap-1">
            <native:text class="text-xl font-bold text-theme-on-background">{{ $profile['name'] }}</native:text>
            <native:text class="text-base text-theme-on-surface-variant">{{ $profile['email'] }}</native:text>
            <native:text class="text-base text-theme-on-surface-variant">{{ $profile['tier']['label'] }} plan</native:text>
        </native:column>
    @endif

    <native:text class="text-sm text-theme-on-surface-variant">WxNotify app version {{ $this->appVersion() }}</native:text>

    <native:button label="Sign out" variant="secondary" @tap="signOut" class="w-full" />
</native:column>
