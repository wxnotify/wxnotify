<x-filament-widgets::widget>
    <x-filament::section heading="7-Day Forecast">
        @php($periods = $this->getPeriods())

        @if (empty($periods))
            <p class="text-sm text-gray-500 dark:text-gray-400">Forecast unavailable right now — try again in a moment.</p>
        @else
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                @foreach ($periods as $period)
                    <div class="rounded-lg border border-gray-200 p-3 text-center dark:border-white/10">
                        <p class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $period['name'] }}</p>

                        @if (! empty($period['icon']))
                            <img src="{{ $period['icon'] }}" alt="" class="mx-auto my-1 h-10 w-10">
                        @endif

                        <p class="text-lg font-semibold">{{ $period['temperature'] }}&deg;{{ $period['temperatureUnit'] }}</p>
                        <p class="text-xs text-gray-500 dark:text-gray-400">{{ $period['shortForecast'] }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
