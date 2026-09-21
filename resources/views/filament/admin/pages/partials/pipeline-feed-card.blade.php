@php
    $feed = $card['feed'];
    $actions = match ($feed->value) {
        'nwws' => ['start' => $this->startAction, 'stop' => $this->stopAction, 'restart' => $this->restartAction],
        'emwin' => ['start' => $this->startEmwinAction, 'stop' => $this->stopEmwinAction, 'restart' => $this->restartEmwinAction],
        'emwin_http' => ['start' => $this->startEmwinHttpAction, 'stop' => $this->stopEmwinHttpAction, 'restart' => $this->restartEmwinHttpAction],
    };
@endphp
<x-filament::section>
    <div style="display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;">
        <div style="display:flex;align-items:flex-start;gap:0.75rem;">
            <x-filament::icon :icon="$feed->icon()" style="width:2rem;height:2rem;flex:none;opacity:0.5;" />
            <div>
                <div style="display:flex;align-items:center;gap:0.75rem;">
                    <span style="font-weight:500;">{{ $feed->label() }}</span>
                    <x-filament::badge :color="$card['health']->color">{{ $card['health']->label }}</x-filament::badge>
                </div>
                <p style="font-size:0.75rem;opacity:0.7;margin:0.125rem 0 0;">{{ $feed->role() }}</p>
                <p style="font-size:0.875rem;opacity:0.7;margin:0.5rem 0 0;">Last product: <span style="font-weight:600;opacity:1;">{{ $card['lastProduct'] ?? 'never' }}</span></p>
            </div>
        </div>
        <div style="display:flex;align-items:center;gap:0.5rem;">
            @if ($actions['start']->isVisible()) {{ $actions['start'] }} @endif
            @if ($actions['stop']->isVisible()) {{ $actions['stop'] }} @endif
            {{ $actions['restart'] }}
        </div>
    </div>

    <div style="margin-top:1rem;">
        <x-pipeline.sparkline :counts="$card['counts']" />
        <p style="font-size:0.75rem;opacity:0.7;margin:0.25rem 0 0;">{{ array_sum($card['counts']) }} received in 24h</p>
    </div>

    @if ($card['recent'] !== null)
        <div style="margin-top:1rem;display:flex;flex-direction:column;gap:0.25rem;">
            <p style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;opacity:0.7;margin:0;">Recent products</p>
            @forelse ($card['recent'] as $delivery)
                <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;font-size:0.75rem;">
                    <span style="font-family:ui-monospace,monospace;">{{ $delivery->wmo_header_key ? strtoupper($delivery->wmo_header_key) : $delivery->file_name }}</span>
                    <span style="display:flex;align-items:center;gap:0.5rem;opacity:0.85;">
                        {{ $delivery->received_at->diffForHumans() }}
                        <x-filament::badge size="sm" :color="$delivery->outcome === 'ingested' ? 'success' : 'gray'">{{ $delivery->outcome === 'ingested' ? 'Ingested' : 'Duplicate' }}</x-filament::badge>
                    </span>
                </div>
            @empty
                <p style="font-size:0.75rem;opacity:0.7;margin:0;">Nothing received yet.</p>
            @endforelse
        </div>
    @endif
</x-filament::section>
