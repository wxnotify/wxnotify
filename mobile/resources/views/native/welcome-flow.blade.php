@use('App\Icons\Ios')
@use('App\Icons\Android')

{{-- The first run has no chrome (nothing to go back to), so it keeps clear of the status bar itself; the replay is pushed from Settings and gets the bar with its back arrow. --}}
@if ($replay)
    <native:top-bar title="Welcome tour" show-navigation-icon="true"></native:top-bar>
@endif

@php($step = $this->step())

<native:column :class="'w-full h-full p-6 gap-4 bg-theme-background' . ($replay ? '' : ' safe-area')">
    <native:row class="w-full items-center justify-between">
        <native:row class="gap-2 items-center" a11y-label="Step {{ $index + 1 }} of {{ count($steps) }}">
            @foreach ($steps as $position => $name)
                <native:column :class="'w-2 h-2 rounded-full ' . ($position === $index ? 'bg-theme-primary' : 'bg-theme-primary/25')"></native:column>
            @endforeach
        </native:row>
        <native:button label="Skip" variant="ghost" @tap="skip" />
    </native:row>

    {{-- Scrolls so a large text size on a small phone cannot push the buttons off the screen; the buttons stay pinned below it. --}}
    <native:scroll-view class="w-full flex-1">
        <native:column class="w-full items-center gap-4 pt-8">
            @if ($step === 'welcome')
                <native:icon :ios="Ios::CloudSunRain" :android="Android::Thunderstorm" size="56" class="text-theme-primary" />
                <native:text class="text-2xl font-bold text-theme-on-background text-center">Welcome to WxNotify</native:text>
                <native:text class="text-base text-theme-on-surface-variant text-center">Weather alerts for the places you care about, on your phone.</native:text>
            @elseif ($step === 'offer')
                <native:icon :ios="Ios::ListBulletRectangle" :android="Android::Checklist" size="56" class="text-theme-primary" />
                <native:text class="text-2xl font-bold text-theme-on-background text-center">What you get</native:text>
                <native:text class="text-base text-theme-on-surface-variant text-center">Alerts you set up once. Current warnings and advisories for your places. A map and every National Weather Service product.</native:text>
            @elseif ($step === 'push')
                <native:icon :ios="Ios::BellBadge" :android="Android::NotificationsActive" size="56" class="text-theme-primary" />
                <native:text class="text-2xl font-bold text-theme-on-background text-center">Get alerts instantly</native:text>
                <native:text class="text-base text-theme-on-surface-variant text-center">Allow notifications so a warning reaches you the moment it's issued, even when the app is closed.</native:text>
            @elseif ($step === 'location')
                <native:icon :ios="Ios::LocationFill" :android="Android::MyLocation" size="56" class="text-theme-primary" />
                <native:text class="text-2xl font-bold text-theme-on-background text-center">Follow your phone</native:text>
                <native:text class="text-base text-theme-on-surface-variant text-center">Share this phone's location and we'll watch the weather wherever you are. It uses one of your places.</native:text>
                <native:text class="text-sm text-theme-on-surface-variant text-center">Your phone will ask for location access. To keep sharing after you close the app, choose "Always" or "Allow all the time" if your phone offers it.</native:text>
            @else
                <native:icon :ios="Ios::CheckmarkCircleFill" :android="Android::CheckCircle" size="56" class="text-theme-primary" />
                <native:text class="text-2xl font-bold text-theme-on-background text-center">You're set</native:text>
                <native:text class="text-base text-theme-on-surface-variant text-center">Add places and alert rules any time from Settings.</native:text>
                @if ($slotNotice === 'used_up')
                    <native:text class="text-sm text-theme-on-background text-center">Your plan's phone-location allowance is used up, so sharing this phone's location isn't available.</native:text>
                @elseif ($slotNotice === 'unknown')
                    <native:text class="text-sm text-theme-on-background text-center">You can share this phone's location later from Settings → Account.</native:text>
                @endif
                @if ($note)
                    <native:text class="text-sm text-theme-on-background text-center">{{ $note }}</native:text>
                @endif
            @endif
        </native:column>
    </native:scroll-view>

    <native:column class="w-full gap-3">
        @if ($step === 'welcome' || $step === 'offer')
            <native:button label="Next" @tap="next({{ $index }})" class="w-full" />
        @elseif ($step === 'push')
            <native:button label="Allow notifications" @tap="allowPush" class="w-full" />
            <native:button label="Not now" variant="secondary" @tap="notNowPush" class="w-full" />
        @elseif ($step === 'location')
            <native:button label="Share location" @tap="shareLocation" class="w-full" />
            <native:button label="Not now" variant="secondary" @tap="notNowLocation" class="w-full" />
        @else
            <native:button :label="$replay ? 'Done' : 'Go to Alerts'" @tap="finish" class="w-full" />
        @endif
    </native:column>
</native:column>
