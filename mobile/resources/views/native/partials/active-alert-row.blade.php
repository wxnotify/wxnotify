<native:column class="w-full gap-1 py-1">
    <native:text class="text-base font-semibold text-theme-on-surface">{{ $screen->activeHeadline($event) }}</native:text>
    <native:text class="text-sm text-theme-on-surface-variant">{{ $screen->activeOverline($event) }}</native:text>
    @if (filled($screen->activePlaces($event)))
        <native:text class="text-sm text-theme-on-surface-variant">{{ $screen->activePlaces($event) }}</native:text>
    @endif
</native:column>
