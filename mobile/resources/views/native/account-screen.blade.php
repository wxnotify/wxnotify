<native:top-bar title="Account" display-mode="large"></native:top-bar>

<native:bottom-nav>
    <native:bottom-nav-item id="alerts" label="Alerts" url="/" icon="notifications"></native:bottom-nav-item>
    <native:bottom-nav-item id="account" label="Account" url="/account" icon="person" active="true"></native:bottom-nav-item>
</native:bottom-nav>

<native:column class="w-full h-full p-4 gap-4 bg-white dark:bg-slate-950">
    @if ($profile !== null)
        <native:column class="w-full gap-1">
            <native:text class="text-xl font-bold text-slate-900 dark:text-slate-50">{{ $profile['name'] }}</native:text>
            <native:text class="text-base text-slate-700 dark:text-slate-300">{{ $profile['email'] }}</native:text>
            <native:text class="text-base text-slate-700 dark:text-slate-300">{{ $profile['tier']['label'] }} plan</native:text>
        </native:column>
    @endif

    <native:text class="text-sm text-slate-700 dark:text-slate-300">WxNotify app version {{ $this->appVersion() }}</native:text>

    {{-- Un-prefixed on purpose: native:validate's element list predates pressable and flags the prefixed form; both compile identically. --}}
    <pressable @tap="signOut" class="w-full py-4 items-center justify-center rounded-xl bg-blue-600 dark:bg-blue-400">
        <native:text class="text-base font-bold text-white dark:text-slate-950">Sign out</native:text>
    </pressable>
</native:column>
