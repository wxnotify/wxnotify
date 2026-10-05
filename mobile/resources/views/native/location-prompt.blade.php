<native:column class="w-full h-full items-center justify-center p-6 gap-4 bg-theme-background">
    <native:text class="text-2xl font-bold text-theme-on-background text-center">Share this phone's location</native:text>
    <native:text class="text-base text-theme-on-surface-variant text-center">WxNotify can keep one of your locations at this phone's position, so alerts follow where you are. It sends only the latest position every few minutes, with no history, even while the app is closed.</native:text>
    <native:text class="text-sm text-theme-on-surface-variant text-center">Your phone will ask for location access. To keep sharing after you close the app, choose "Always" or "Allow all the time" if your phone offers it; otherwise sharing may stop when you close the app. Android shows a notification while sharing is on.</native:text>

    @if ($error)
        <native:text class="text-sm text-theme-on-background text-center">{{ $error }}</native:text>
    @endif

    <native:button label="Share my location" @tap="share" class="w-full" />
    <native:button label="Not now" variant="secondary" @tap="notNow" class="w-full" />
</native:column>
