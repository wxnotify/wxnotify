<native:column class="w-full h-full items-center justify-center p-6 gap-4 bg-white dark:bg-slate-950">
    @if ($error)
        <native:text class="text-base text-slate-900 dark:text-slate-50 text-center">{{ $error }}</native:text>
        <native:pressable @tap="tryAgain" class="w-full py-4 items-center justify-center rounded-xl bg-blue-600 dark:bg-blue-400">
            <native:text class="text-base font-bold text-white dark:text-slate-950">Try again</native:text>
        </native:pressable>
    @else
        <native:text class="text-base text-slate-700 dark:text-slate-300">Signing you in…</native:text>
    @endif
</native:column>
