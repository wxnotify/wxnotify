<x-filament-panels::page>
    <div wire:poll.10s class="space-y-6">
        @php($summary = $this->summary())
        @php($cards = collect($this->cards())->keyBy(fn ($card) => $card['feed']->value))

        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <x-filament::badge :color="$summary->color" size="lg">{{ $summary->headline }}</x-filament::badge>
                    @foreach ($summary->notes as $note)
                        <span class="text-sm text-gray-500 dark:text-gray-400">{{ $note }}</span>
                    @endforeach
                </div>
                <span class="text-sm text-gray-500 dark:text-gray-400">{{ number_format($this->ingestedToday()) }} products ingested today</span>
            </div>
        </x-filament::section>

        <div class="space-y-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Primary feeds</h3>
            @include('filament.admin.pages.partials.pipeline-feed-card', ['card' => $cards['nwws']])
            @include('filament.admin.pages.partials.pipeline-feed-card', ['card' => $cards['emwin']])
        </div>

        <div class="space-y-3">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Backup feed</h3>
            @include('filament.admin.pages.partials.pipeline-feed-card', ['card' => $cards['emwin_http']])
        </div>

        {{-- Recent EMWIN products: shared table, one row per product, a column per feed --}}
        <x-filament::section heading="Recent EMWIN products" description="Each product should arrive on both feeds; the second delivery is deduplicated.">
            @php($rows = $this->emwinComparison())
            @if ($rows->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">No EMWIN deliveries recorded yet.</p>
            @else
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="text-gray-500 dark:text-gray-400">
                            <th class="py-2 pr-4 font-medium">Product</th>
                            <th class="py-2 pr-4 font-medium">EMWIN Byteblaster</th>
                            <th class="py-2 font-medium">EMWIN HTTP backup</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-gray-200 dark:border-white/10">
                                <td class="py-2 pr-4 font-mono text-xs">{{ $row['label'] }}
                                    @unless ($row['comparable'])
                                        <span class="ml-2 text-gray-400">No header to compare</span>
                                    @endunless
                                </td>
                                @foreach (['emwin', 'emwin_http'] as $feed)
                                    @php($cell = $row['cells'][$feed])
                                    <td class="py-2 {{ $feed === 'emwin' ? 'pr-4' : '' }}">
                                        @if ($cell['state'] === 'delivered')
                                            <x-filament::badge color="success">{{ $cell['at']->format('H:i:s') }}</x-filament::badge>
                                        @elseif ($cell['state'] === 'waiting')
                                            <x-filament::badge color="gray">Waiting</x-filament::badge>
                                        @elseif ($cell['state'] === 'missing')
                                            <x-filament::badge color="warning">Not received</x-filament::badge>
                                        @else
                                            <span class="text-gray-400">—</span>
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
