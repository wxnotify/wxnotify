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
                {{ $this->startAction }}
                {{ $this->stopAction }}
                {{ $this->restartAction }}
            </div>
        </div>

        @foreach (['EMWIN Byteblaster', 'EMWIN HTTP backup'] as $client)
            <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
                <span class="font-medium text-gray-700 dark:text-gray-200">{{ $client }}</span>
                <span class="rounded-full bg-gray-100 px-3 py-1 text-xs font-medium text-gray-500 dark:bg-white/5 dark:text-gray-400">
                    Status unknown
                </span>
            </div>
        @endforeach
    </div>
</x-filament-panels::page>
