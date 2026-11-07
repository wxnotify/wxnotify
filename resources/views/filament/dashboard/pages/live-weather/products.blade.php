@php
    $timezone = $this->getTimezone();
@endphp

<x-filament-panels::page>
    <div
        data-live-products
        x-effect="if (! scrolled && $wire.pending > 0) { $wire.pending = 0; }"
        x-data="{
            scrolled: false,
            init() {
                this.onScroll = () => {
                    this.scrolled = window.scrollY > 160;
                    if (! this.scrolled && $wire.pending > 0) { $wire.pending = 0; }
                };
                window.addEventListener('scroll', this.onScroll, { passive: true });
            },
            destroy() { window.removeEventListener('scroll', this.onScroll); },
            toTop() { window.scrollTo({ top: 0, behavior: 'smooth' }); $wire.pending = 0; },
        }"
    >
        <div class="mb-4 flex flex-wrap gap-2" data-category-chips>
            @foreach ($this->getCategoryOptions() as $option)
                @php
                    $active = in_array($option['value'], $categories, true);
                    $next = $active
                        ? array_values(array_diff($categories, [$option['value']]))
                        : [...$categories, $option['value']];
                @endphp
                <button
                    type="button"
                    wire:click="setCategories(@js($next))"
                    aria-pressed="{{ $active ? 'true' : 'false' }}"
                    @class([
                        'rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset',
                        'bg-primary-600 text-white ring-primary-600' => $active,
                        'bg-white text-gray-700 ring-gray-300 dark:bg-gray-900 dark:text-gray-300 dark:ring-white/20' => ! $active,
                    ])
                >{{ $option['label'] }}</button>
            @endforeach
        </div>

        <div
            x-show="scrolled && $wire.pending > 0"
            x-cloak
            class="sticky top-20 z-[25] mb-3 flex justify-center"
        >
            <button type="button" x-on:click="toTop()" class="rounded-full bg-primary-600 px-4 py-1.5 text-sm font-medium text-white shadow-lg">
                <span x-text="$wire.pending + ($wire.pending === 1 ? ' new product' : ' new products')"></span>
            </button>
        </div>

        @if (count($rows) === 0)
            <p class="text-sm text-gray-500 dark:text-gray-400">No products to show yet.</p>
        @else
            <ul class="divide-y divide-gray-200 overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:divide-white/10 dark:bg-gray-900 dark:ring-white/10" data-product-list>
                @foreach ($rows as $row)
                    <li wire:key="product-{{ $row['id'] }}" class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 px-4 py-3" data-product-row>
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-gray-950 dark:text-white">{{ $row['name'] }}</p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ \Illuminate\Support\Carbon::parse($row['issued_at'])->setTimezone($timezone)->format('M j, g:i A T') }}
                                @if ($row['office'])
                                    &middot; {{ $row['office'] }}
                                @endif
                                &middot; {{ \App\Support\LiveProductCategory::from($row['category'])->label() }}
                            </p>
                            @if ($row['headline'])
                                <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">{{ $row['headline'] }}</p>
                            @endif
                        </div>
                        @if ($row['view_url'] && str_starts_with($row['view_url'], 'https://'))
                            <a href="{{ $row['view_url'] }}" target="_blank" rel="noopener" class="text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">View</a>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</x-filament-panels::page>
