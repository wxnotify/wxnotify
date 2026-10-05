<native:top-bar title="Alert" show-navigation-icon="true"></native:top-bar>

<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-4">
        @if ($offline && $alert !== null)
            <native:text class="text-sm text-theme-on-surface-variant">You're offline. Showing the last copy of this alert.</native:text>
        @endif

        @if ($alert === null)
            <native:text class="text-base text-theme-on-background">{{ $message }}</native:text>
        @else
            <native:text class="text-xl font-bold text-theme-on-background">{{ $alert['subject'] }}</native:text>
            <native:text class="text-sm text-theme-on-surface-variant">{{ $this->categoryAndSentAt() }}</native:text>

            @if (filled($alert['rule_name'] ?? null))
                <native:text class="text-sm text-theme-on-surface-variant">Rule: {{ $alert['rule_name'] }}</native:text>
            @endif

            <native:divider></native:divider>

            @foreach ($alert['channels'] as $channel)
                <native:row class="w-full justify-between">
                    <native:text class="text-base text-theme-on-background">{{ $channel['label'] ?? $channel['type_label'] }}</native:text>
                    <native:text class="text-base {{ $channel['status'] === 'failure' ? 'text-theme-destructive' : 'text-theme-on-surface-variant' }}">{{ $this->statusLabel($channel['status']) }}</native:text>
                </native:row>
            @endforeach

            @if (filled($alert['product_url'] ?? null))
                <native:button label="View full product" @tap="openProduct" class="w-full" />
            @endif
        @endif
    </native:column>
</native:scroll-view>
