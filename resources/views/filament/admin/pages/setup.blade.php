<x-filament-panels::page>
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ($this->getSets() as $set)
            <x-filament::section>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-medium text-gray-950 dark:text-white">{{ $set['label'] }}</div>
                        <div class="mt-1 text-2xl font-semibold text-gray-950 dark:text-white">{{ number_format($set['count']) }}</div>
                    </div>
                    <x-filament::badge :color="$set['installed'] ? 'success' : 'warning'">
                        {{ $set['installed'] ? 'Installed' : 'Not installed' }}
                    </x-filament::badge>
                </div>
            </x-filament::section>
        @endforeach
    </div>

    <div class="flex gap-3">
        {{ $this->installReferenceDataAction }}
    </div>
</x-filament-panels::page>
