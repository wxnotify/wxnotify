<x-filament-panels::page>
    <div class="space-y-3">
        <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-center gap-3">
                <span class="font-medium text-gray-700 dark:text-gray-200">NWWS</span>
                <x-filament::badge :color="$this->nwwsStatusColor()">
                    {{ $this->nwwsStatusLabel() }}
                </x-filament::badge>
            </div>
            <div class="flex items-center gap-2">
                @if ($this->startAction->isVisible())
                    {{ $this->startAction }}
                @endif
                @if ($this->stopAction->isVisible())
                    {{ $this->stopAction }}
                @endif
                {{ $this->restartAction }}
            </div>
        </div>

        <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-center gap-3">
                <span class="font-medium text-gray-700 dark:text-gray-200">EMWIN Byteblaster</span>
                <x-filament::badge :color="$this->emwinStatusColor()">
                    {{ $this->emwinStatusLabel() }}
                </x-filament::badge>
                <span class="text-xs text-gray-500 dark:text-gray-400">Last product: {{ $this->emwinLastProductAt() ?? 'never' }}</span>
            </div>
            <div class="flex items-center gap-2">
                @if ($this->startEmwinAction->isVisible())
                    {{ $this->startEmwinAction }}
                @endif
                @if ($this->stopEmwinAction->isVisible())
                    {{ $this->stopEmwinAction }}
                @endif
                {{ $this->restartEmwinAction }}
            </div>
        </div>

        <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="flex items-center gap-3">
                <span class="font-medium text-gray-700 dark:text-gray-200">EMWIN HTTP backup</span>
                <x-filament::badge :color="$this->emwinHttpStatusColor()">
                    {{ $this->emwinHttpStatusLabel() }}
                </x-filament::badge>
                <span class="text-xs text-gray-500 dark:text-gray-400">Last product: {{ $this->emwinHttpLastProductAt() ?? 'never' }}</span>
            </div>
            <div class="flex items-center gap-2">
                @if ($this->startEmwinHttpAction->isVisible())
                    {{ $this->startEmwinHttpAction }}
                @endif
                @if ($this->stopEmwinHttpAction->isVisible())
                    {{ $this->stopEmwinHttpAction }}
                @endif
                {{ $this->restartEmwinHttpAction }}
            </div>
        </div>
    </div>
</x-filament-panels::page>
