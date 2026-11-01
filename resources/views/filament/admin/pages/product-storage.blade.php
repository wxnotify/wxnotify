<x-filament-panels::page>
    @php
        $status = $this->statusLine();
        $colours = ['normal' => '#16a34a', 'warning' => '#d97706', 'critical' => '#dc2626'];
    @endphp

    <div wire:poll.15s style="display:flex;flex-direction:column;gap:1.5rem;">
        <x-filament::section>
            <p style="margin:0;font-size:0.875rem;font-weight:500;color:{{ $colours[$status['tone']] ?? 'inherit' }};">{{ $status['text'] }}</p>
        </x-filament::section>

        @foreach ($this->gauges() as $gauge)
            <x-filament::section :heading="$gauge['label']">
                @if ($gauge['available'])
                    <div style="height:0.75rem;border-radius:9999px;background:rgba(128,128,128,0.25);overflow:hidden;">
                        <div style="height:100%;width:{{ min(100, $gauge['percent']) }}%;background:{{ $colours[$gauge['band']] }};"></div>
                    </div>
                    <p style="margin:0.75rem 0 0;font-size:0.875rem;">{{ $gauge['usage'] }}</p>
                    @if ($gauge['split'])
                        <p style="margin:0.25rem 0 0;font-size:0.875rem;opacity:0.7;">{{ $gauge['split'] }}</p>
                    @endif
                @else
                    <p style="margin:0;font-size:0.875rem;opacity:0.7;">Unavailable: {{ $gauge['path'] }} could not be read</p>
                @endif
            </x-filament::section>
        @endforeach
    </div>
</x-filament-panels::page>
