<x-filament-widgets::widget>
    <x-filament::section compact>
        <x-slot name="heading">
            Needs attention
            @if ($count > 0)
                <x-filament::badge color="warning" size="sm" class="ml-2 inline-flex">{{ $count }}</x-filament::badge>
            @endif
        </x-slot>

        @if ($rows === [])
            <div class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 text-success-600 dark:text-success-400" />
                <span>All your alerts look healthy.</span>
            </div>
        @else
            <ul class="divide-y divide-gray-100 dark:divide-white/10">
                @foreach ($rows as $row)
                    <li class="flex items-start gap-3 py-2">
                        <x-filament::icon :icon="$row['icon']" class="mt-0.5 h-5 w-5 shrink-0 {{ $row['icon_class'] }}" />
                        <div class="min-w-0 flex-1 text-sm">
                            <span class="font-medium text-gray-950 dark:text-white">{{ $row['subject'] }}</span>
                            <span class="text-gray-600 dark:text-gray-400">{{ $row['detail'] }}</span>
                        </div>
                        <a href="{{ $row['url'] }}" class="shrink-0 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">Fix</a>
                    </li>
                @endforeach
            </ul>

            @if ($more > 0)
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">and {{ $more }} more</p>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
