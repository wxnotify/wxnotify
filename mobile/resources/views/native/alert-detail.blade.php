<native:top-bar title="Alert" show-navigation-icon="true"></native:top-bar>

<native:scroll-view class="w-full h-full bg-white dark:bg-slate-950">
    <native:column class="w-full p-4 gap-4">
        @if ($offline && $alert !== null)
            <native:text class="text-sm text-slate-700 dark:text-slate-300">You're offline. Showing the last copy of this alert.</native:text>
        @endif

        @if ($alert === null)
            <native:text class="text-base text-slate-900 dark:text-slate-50">{{ $message }}</native:text>
        @else
            <native:text class="text-xl font-bold text-slate-900 dark:text-slate-50">{{ $alert['subject'] }}</native:text>
            <native:text class="text-sm text-slate-700 dark:text-slate-300">{{ $alert['category_label'] }} · {{ $this->sentAt() }}</native:text>

            @if (filled($alert['rule_name'] ?? null))
                <native:text class="text-sm text-slate-700 dark:text-slate-300">Rule: {{ $alert['rule_name'] }}</native:text>
            @endif

            <native:divider></native:divider>

            @foreach ($alert['channels'] as $channel)
                <native:row class="w-full justify-between">
                    <native:text class="text-base text-slate-900 dark:text-slate-50">{{ $channel['label'] ?? $channel['type_label'] }}</native:text>
                    <native:text class="text-base {{ $channel['status'] === 'failure' ? 'text-red-600 dark:text-red-400' : 'text-slate-700 dark:text-slate-300' }}">{{ $this->statusLabel($channel['status']) }}</native:text>
                </native:row>
            @endforeach

            @if (filled($alert['product_url'] ?? null))
                {{-- Un-prefixed on purpose: native:validate's element list predates pressable and flags the prefixed form; both compile identically. --}}
                <pressable @tap="openProduct" class="w-full py-4 items-center justify-center rounded-xl bg-blue-600 dark:bg-blue-400">
                    <native:text class="text-base font-bold text-white dark:text-slate-950">View full product</native:text>
                </pressable>
            @endif
        @endif
    </native:column>
</native:scroll-view>
