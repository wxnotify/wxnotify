<native:column class="w-full h-full items-center justify-center p-6 gap-4 bg-theme-background">
    <native:text class="text-2xl font-bold text-theme-on-background text-center">Get alerts on this phone</native:text>
    <native:text class="text-base text-theme-on-surface-variant text-center">WxNotify can send the alerts you choose straight to this phone as notifications. You pick which alerts in your alert rules at wxnotify.com.</native:text>

    <native:button label="Enable notifications" @tap="enable" class="w-full" />
    <native:button label="Not now" variant="secondary" @tap="notNow" class="w-full" />
</native:column>
