<native:top-bar title="Alerts" display-mode="large"></native:top-bar>

<native:bottom-nav>
    <native:bottom-nav-item id="alerts" label="Alerts" url="/" icon="notifications" active="true"></native:bottom-nav-item>
    <native:bottom-nav-item id="account" label="Account" url="/account" icon="person"></native:bottom-nav-item>
</native:bottom-nav>

<native:column class="w-full h-full bg-white dark:bg-slate-950">
    @if ($offline)
        <native:row class="w-full p-3 bg-slate-100 dark:bg-slate-800">
            <native:text class="text-sm text-slate-700 dark:text-slate-300">You're offline. Showing alerts from {{ $this->syncedLabel() }}.</native:text>
        </native:row>
    @endif

    @if ($error)
        <native:row class="w-full p-3 bg-slate-100 dark:bg-slate-800">
            <native:text class="text-sm text-red-600 dark:text-red-400">{{ $error }}</native:text>
        </native:row>
    @endif

    {{-- Un-prefixed on purpose, like pressable below: native:validate's element list predates refreshable. --}}
    <refreshable @refresh="refresh">
        @forelse ($alerts as $alert)
            {{-- Un-prefixed on purpose: native:validate's element list predates pressable and flags the prefixed form; both compile identically. --}}
            <pressable @tap="open({{ $alert['id'] }})" key="alert-{{ $alert['id'] }}" class="w-full">
                <native:column class="w-full p-4 gap-1">
                    <native:text class="text-base font-semibold text-slate-900 dark:text-slate-50">{{ $alert['subject'] }}</native:text>
                    <native:text class="text-sm text-slate-700 dark:text-slate-300">{{ $this->subtitle($alert) }}</native:text>
                    <native:text class="text-sm text-slate-700 dark:text-slate-300">{{ $this->channels($alert) }}</native:text>
                    @if ($alert['has_failure'])
                        <native:text class="text-sm text-red-600 dark:text-red-400">Delivery problem</native:text>
                    @endif
                </native:column>
            </pressable>
            <native:divider></native:divider>
        @empty
            <native:column class="w-full p-6 items-center gap-2">
                <native:text class="text-lg font-semibold text-slate-900 dark:text-slate-50">No alerts yet</native:text>
                <native:text class="text-sm text-slate-700 dark:text-slate-300 text-center">Alerts you receive show up here once one of your rules matches.</native:text>
            </native:column>
        @endforelse

        @if ($nextCursor !== null)
            <pressable @tap="loadMore" class="m-4 py-4 items-center justify-center rounded-xl bg-blue-600 dark:bg-blue-400">
                <native:text class="text-base font-bold text-white dark:text-slate-950">Load more</native:text>
            </pressable>
        @endif
    </refreshable>
</native:column>
