<x-filament-panels::page>
    <div wire:poll.10s style="display:flex;flex-direction:column;gap:1.5rem;">
        @php($summary = $this->summary())
        @php($cards = collect($this->cards())->keyBy(fn ($card) => $card['feed']->value))

        <x-filament::section>
            <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:0.75rem;">
                <div style="display:flex;flex-wrap:wrap;align-items:center;gap:0.75rem;">
                    <x-filament::badge :color="$summary->color" size="lg">{{ $summary->headline }}</x-filament::badge>
                    @foreach ($summary->notes as $note)
                        <span style="font-size:0.875rem;opacity:0.7;">{{ $note }}</span>
                    @endforeach
                </div>
                <span style="font-size:0.875rem;opacity:0.7;">{{ number_format($this->ingestedToday()) }} products ingested today</span>
            </div>
        </x-filament::section>

        <div style="display:flex;flex-direction:column;gap:0.75rem;">
            <h3 style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;opacity:0.7;margin:0;">Primary feeds</h3>
            @include('filament.admin.pages.partials.pipeline-feed-card', ['card' => $cards['nwws']])
            @include('filament.admin.pages.partials.pipeline-feed-card', ['card' => $cards['emwin']])
        </div>

        <div style="display:flex;flex-direction:column;gap:0.75rem;">
            <h3 style="font-size:0.75rem;font-weight:600;text-transform:uppercase;letter-spacing:0.05em;opacity:0.7;margin:0;">Backup feed</h3>
            @include('filament.admin.pages.partials.pipeline-feed-card', ['card' => $cards['emwin_http']])
        </div>

        {{-- Recent EMWIN products: shared table, one row per product, a column per feed --}}
        <x-filament::section heading="Recent EMWIN products" description="Each product should arrive on both feeds; the second delivery is deduplicated.">
            @php($rows = $this->emwinComparison())
            @if ($rows->isEmpty())
                <p style="font-size:0.875rem;opacity:0.7;">No EMWIN deliveries recorded yet.</p>
            @else
                <table style="width:100%;text-align:left;font-size:0.875rem;border-collapse:collapse;">
                    <thead>
                        <tr style="opacity:0.7;">
                            <th style="padding:0.5rem 1rem 0.5rem 0;font-weight:500;">Product</th>
                            <th style="padding:0.5rem 1rem 0.5rem 0;font-weight:500;">EMWIN Byteblaster</th>
                            <th style="padding:0.5rem 0;font-weight:500;">EMWIN HTTP backup</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr style="border-top:1px solid rgba(128,128,128,0.25);">
                                <td style="padding:0.5rem 1rem 0.5rem 0;font-family:ui-monospace,monospace;font-size:0.75rem;">{{ $row['label'] }}
                                    @unless ($row['comparable'])
                                        <span style="margin-left:0.5rem;opacity:0.5;">No header to compare</span>
                                    @endunless
                                </td>
                                @foreach (['emwin', 'emwin_http'] as $feed)
                                    @php($cell = $row['cells'][$feed])
                                    <td style="padding:0.5rem {{ $feed === 'emwin' ? '1rem' : '0' }} 0.5rem 0;">
                                        @if ($cell['state'] === 'delivered')
                                            <x-filament::badge color="success">{{ $cell['at']->format('H:i:s') }}</x-filament::badge>
                                        @elseif ($cell['state'] === 'waiting')
                                            <x-filament::badge color="gray">Waiting</x-filament::badge>
                                        @elseif ($cell['state'] === 'missing')
                                            <x-filament::badge color="warning">Not received</x-filament::badge>
                                        @else
                                            <span style="opacity:0.5;">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
