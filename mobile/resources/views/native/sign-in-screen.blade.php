<native:column class="w-full h-full items-center justify-center p-6 gap-6 bg-theme-background">
    <native:image src="icon.png" alt="WxNotify" class="w-24 h-24" />
    <native:text class="text-2xl font-bold text-theme-on-background text-center">WxNotify</native:text>
    <native:text class="text-base text-theme-on-surface-variant text-center">Weather alerts for the places that matter to you.</native:text>
    @if ($error)
        <native:text class="text-sm text-theme-destructive text-center">{{ $error }}</native:text>
    @endif
    <native:button label="Sign in to WxNotify" size="lg" @tap="signIn" class="w-full" />
</native:column>
