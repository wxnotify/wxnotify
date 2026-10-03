<x-filament-widgets::widget>
    <x-filament::section heading="Active now at your locations" compact>
        <div wire:poll.60s>
            @if (! $has_locations)
                <p class="text-sm text-gray-600 dark:text-gray-300">Add a location to see active weather alerts here.</p>
            @elseif (! $has_position)
                <p class="text-sm text-gray-600 dark:text-gray-300">None of your locations has a position yet, so there's nothing to check.</p>
            @elseif ($events === [])
                <p class="text-sm text-gray-600 dark:text-gray-300">No active weather alerts at your locations.</p>
            @else
                <ul class="divide-y divide-gray-100 dark:divide-white/10">
                    @foreach ($events as $event)
                        <li class="py-2 text-sm">
                            <div class="flex flex-wrap items-center gap-2">
                                <x-filament::badge :color="match ($event['family']) { 'warning' => 'danger', 'watch' => 'warning', 'advisory' => 'info', default => 'gray' }" size="sm">{{ $event['event'] }}</x-filament::badge>
                                <span class="text-gray-700 dark:text-gray-300">{{ implode(', ', $event['locations']) }}</span>
                                @if ($event['expires_label'])
                                    <span class="text-gray-500 dark:text-gray-400">until {{ $event['expires_label'] }}</span>
                                @endif
                            </div>
                            <div class="mt-1">
                                @switch($event['status'])
                                    @case('alerted')
                                        <span class="text-success-700 dark:text-success-400">Alerted via {{ implode(', ', $event['alerted_via']) }}@if ($event['alerted_at_label']) · {{ $event['alerted_at_label'] }}@endif</span>
                                        @break
                                    @case('held')
                                        <span class="text-warning-700 dark:text-warning-400">Not sent (quiet hours)</span>
                                        @break
                                    @case('failed')
                                        <span class="text-danger-700 dark:text-danger-400">Delivery failed</span>
                                        @break
                                    @default
                                        <span class="font-medium text-danger-700 dark:text-danger-400">No alert sent for this warning</span>
                                @endswitch
                            </div>
                        </li>
                    @endforeach
                </ul>

                @if ($more > 0)
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">and {{ $more }} more</p>
                @endif

                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">"No alert sent" means none of your alerts delivered this warning. A warning issued before you created an alert may show this even though updates to it may reach you.@if ($capped) Showing your first 25 locations.@endif</p>
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
