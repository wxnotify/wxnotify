<native:column class="w-full h-full items-center justify-center p-6 gap-6 bg-white dark:bg-slate-950">
    <native:image src="icon.png" alt="WxNotify" class="w-24 h-24" />
    <native:text class="text-2xl font-bold text-slate-900 dark:text-slate-50 text-center">WxNotify</native:text>
    <native:text class="text-base text-slate-700 dark:text-slate-300 text-center">Weather alerts for the places that matter to you.</native:text>
    @if ($error)
        <native:text class="text-sm text-red-600 dark:text-red-400 text-center">{{ $error }}</native:text>
    @endif
    <native:pressable @tap="signIn" class="w-full py-4 items-center justify-center rounded-xl bg-blue-600 dark:bg-blue-400">
        <native:text class="text-base font-bold text-white dark:text-slate-950">Sign in to WxNotify</native:text>
    </native:pressable>
</native:column>
