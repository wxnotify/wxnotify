<native:column class="w-full h-full items-center justify-center p-6 gap-4 bg-theme-background">
    @if ($error)
        <native:text class="text-base text-theme-on-background text-center">{{ $error }}</native:text>
        <native:button label="Try again" @tap="tryAgain" class="w-full" />
    @else
        <native:activity-indicator size="lg" />
        <native:text class="text-base text-theme-on-surface-variant">Signing you in…</native:text>
    @endif
</native:column>
