<native:top-bar title="Account" display-mode="large"></native:top-bar>

@include('native.partials.tabs', ['active' => 'account'])

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
        @php($status = $this->pushStatus())
        <native:text class="text-base text-theme-on-surface-variant">{{ $this->pushStatusLabel($status) }}</native:text>

        @if ($status === 'on')
            <native:button label="Turn off" variant="secondary" @tap="disablePush" class="w-full" />
        @elseif ($status === 'blocked')
            <native:button label="Open system settings" variant="secondary" @tap="openSystemSettings" class="w-full" />
            @if ($this->pushWanted())
                <native:button label="Turn off" variant="secondary" @tap="disablePush" class="w-full" />
            @endif
        @else
            <native:button label="Turn on" variant="secondary" @tap="enablePush" class="w-full" />
        @endif

        @if ($pushMessage)
            <native:text class="text-sm text-theme-on-surface-variant">{{ $pushMessage }}</native:text>
        @endif

        <native:text class="text-sm text-theme-on-surface-variant">Choose which alerts go to this phone in your alert rules at wxnotify.com.</native:text>
    </native:column>

    <native:column class="w-full gap-2">
        <native:text class="text-base font-bold text-theme-on-background">Share this phone's location</native:text>
        @php($location = $this->locationState())
        <native:text class="text-base text-theme-on-surface-variant">{{ $this->locationStatusLabel($location) }}</native:text>

        @if ($location['status'] === 'on')
            @if ($location['while_using_only'])
                <native:text class="text-sm text-theme-on-surface-variant">Stops when you close the app. Allow location "Always" in your phone's settings to keep it going.</native:text>
                <native:button label="Open settings" variant="secondary" @tap="openLocationSettings" class="w-full" />
            @endif
            <native:button label="Stop sharing" variant="secondary" @tap="stopSharingLocation" class="w-full" />
        @elseif ($location['status'] === 'waiting')
            <native:button label="Open settings" variant="secondary" @tap="openLocationSettings" class="w-full" />
            <native:button label="Stop sharing" variant="secondary" @tap="stopSharingLocation" class="w-full" />
        @else
            <native:button label="Share location" variant="secondary" @tap="shareLocation" class="w-full" />
        @endif

        @if ($locationMessage)
            <native:text class="text-sm text-theme-on-surface-variant">{{ $locationMessage }}</native:text>
        @endif
    </native:column>

    <native:text class="text-sm text-theme-on-surface-variant">WxNotify app version {{ $this->appVersion() }}</native:text>

    <native:button label="Sign out" variant="secondary" @tap="signOut" class="w-full" />
</native:column>
