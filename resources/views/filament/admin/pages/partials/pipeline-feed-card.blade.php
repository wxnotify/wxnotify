@php
    $feed = $card['feed'];
    $actions = match ($feed->value) {
        'nwws' => ['start' => $this->startAction, 'stop' => $this->stopAction, 'restart' => $this->restartAction],
        'emwin' => ['start' => $this->startEmwinAction, 'stop' => $this->stopEmwinAction, 'restart' => $this->restartEmwinAction],
        'emwin_http' => ['start' => $this->startEmwinHttpAction, 'stop' => $this->stopEmwinHttpAction, 'restart' => $this->restartEmwinHttpAction],
    };
@endphp
<x-filament::section>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="flex items-start gap-3">
            <x-filament::icon :icon="$feed->icon()" class="h-8 w-8 text-gray-400" />
            <div>
                <div class="flex items-center gap-3">
                    <span class="font-medium text-gray-700 dark:text-gray-200">{{ $feed->label() }}</span>
                    <x-filament::badge :color="$card['health']->color">{{ $card['health']->label }}</x-filament::badge>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ $feed->role() }}</p>
                <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">Last product: <span class="font-semibold text-gray-800 dark:text-gray-100">{{ $card['lastProduct'] ?? 'never' }}</span></p>
            </div>
        </div>
        <div class="flex items-center gap-2">
            @if ($actions['start']->isVisible()) {{ $actions['start'] }} @endif
            @if ($actions['stop']->isVisible()) {{ $actions['stop'] }} @endif
            {{ $actions['restart'] }}
        </div>
    </div>

    <div class="mt-4">
        <x-pipeline.sparkline :counts="$card['counts']" />
        <p class="text-xs text-gray-500 dark:text-gray-400">{{ array_sum($card['counts']) }} received in 24h</p>
    </div>

    @if ($card['recent'] !== null)
        <div class="mt-4 space-y-1">
            <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Recent products</p>
            @forelse ($card['recent'] as $delivery)
                <div class="flex items-center justify-between text-xs">
                    <span class="font-mono">{{ $delivery->wmo_header_key ? strtoupper($delivery->wmo_header_key) : $delivery->file_name }}</span>
                    <span class="flex items-center gap-2 text-gray-500 dark:text-gray-400">
                        {{ $delivery->received_at->diffForHumans() }}
                        <x-filament::badge size="sm" :color="$delivery->outcome === 'ingested' ? 'success' : 'gray'">{{ $delivery->outcome === 'ingested' ? 'Ingested' : 'Duplicate' }}</x-filament::badge>
                    </span>
                </div>
            @empty
                <p class="text-xs text-gray-500 dark:text-gray-400">Nothing received yet.</p>
            @endforelse
        </div>
    @endif
</x-filament::section>
