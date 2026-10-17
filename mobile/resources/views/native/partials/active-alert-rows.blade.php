@foreach ($screen->activeRows() as $event)
    @if (filled($event['view_url'] ?? null))
        <pressable @tap="openActive({{ $event['id'] }})" ref="active-{{ $event['id'] }}" class="w-full">
            @include('native.partials.active-alert-row', ['event' => $event, 'screen' => $screen])
        </pressable>
    @else
        @include('native.partials.active-alert-row', ['event' => $event, 'screen' => $screen])
    @endif
@endforeach
