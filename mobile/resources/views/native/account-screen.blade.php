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

    <native:column class="w-full gap-2">
        <native:text class="text-base font-bold text-theme-on-background">Push notifications</native:text>
        <native:text class="text-base text-theme-on-surface-variant">{{ $this->pushStatusLabel() }}</native:text>

        @if ($this->pushStatus() === 'on')
            <native:button label="Turn off" variant="secondary" @tap="disablePush" class="w-full" />
        @elseif ($this->pushStatus() === 'blocked')
            <native:button label="Open system settings" variant="secondary" @tap="openSystemSettings" class="w-full" />
        @else
            <native:button label="Turn on" variant="secondary" @tap="enablePush" class="w-full" />
        @endif

        @if ($pushMessage)
            <native:text class="text-sm text-theme-on-surface-variant">{{ $pushMessage }}</native:text>
        @endif

        <native:text class="text-sm text-theme-on-surface-variant">Choose which alerts go to this phone in your alert rules at wxnotify.com.</native:text>
    </native:column>

    <native:text class="text-sm text-theme-on-surface-variant">WxNotify app version {{ $this->appVersion() }}</native:text>

    <native:button label="Sign out" variant="secondary" @tap="signOut" class="w-full" />
</native:column>
