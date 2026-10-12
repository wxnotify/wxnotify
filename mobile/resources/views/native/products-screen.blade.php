@use('App\Icons\Ios')
@use('App\Icons\Android')

{{-- Un-prefixed on purpose: native:validate does not know <pressable>, <select> or <outlined-text-input>, nor @change on <select> (a bare tag compiles the same). Handler arguments go through {{ }} so the validator skips them. --}}

<native:top-bar title="Products" display-mode="large"></native:top-bar>

@include('native.partials.tabs', ['active' => 'products'])

<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-3">
        <native:row class="w-full items-center gap-2">
            <outlined-text-input label="Search products" placeholder="Name or ID, such as AFD or AFDMOB" native:model.blur="query" max-length="100" @submit="search" ref="field-query" class="flex-1" />
            {{-- Shows and hides the filters. The icon takes the primary colour while any filter is set, so a hidden filter is never invisible. --}}
            <pressable @tap="toggleFilters" ref="toggle-filters" a11y-label="Filters">
                <native:icon :ios="Ios::SliderHorizontal3" :android="Android::Tune" size="26" :color="$this->hasFilters() ? theme('primary') : theme('on-surface-variant')" />
            </pressable>
        </native:row>
        {{-- A pressable, not <native:button>: on Android only a pressable clears the text field's focus first, which flushes the typed text (native:model.blur) before the handler runs. --}}
        <pressable @tap="search" ref="search-products" class="w-full">
            <native:column class="w-full p-3 items-center bg-theme-primary rounded-full">
                <native:text class="text-base font-semibold text-theme-on-primary">Search</native:text>
            </native:column>
        </pressable>

        @if ($filtersOpen)
            <select label="Type" :options="$this->typeLabels()" :value="$this->selectedTypeLabel()" @change="pickType" ref="field-type" />
            <select label="Office" :options="$this->officeLabels()" :value="$this->selectedOfficeLabel()" @change="pickOffice" ref="field-office" />
            <select label="Period" :options="$this->periodLabels()" :value="$this->selectedPeriodLabel()" @change="pickPeriod" ref="field-period" />

            @if ($this->hasFilters())
                <pressable @tap="clearFilters" ref="clear-filters" class="w-full">
                    <native:text class="text-sm font-semibold text-theme-primary p-2">Clear filters</native:text>
                </pressable>
            @endif
        @endif

        @if ($offline)
            <native:text class="text-sm text-theme-on-surface-variant">You're offline. Reconnect to search.</native:text>
        @endif

        @if ($message)
            <native:text class="text-sm text-theme-on-surface-variant">{{ $message }}</native:text>
        @endif

        @if ($error)
            <native:text class="text-sm text-theme-destructive">{{ $error }}</native:text>
            <native:button label="Try again" variant="secondary" @tap="search" ref="retry-search" class="w-full" />
        @endif

        @if ($results === null)
            <native:text class="text-sm text-theme-on-surface-variant">Search by name or ID, or use the filters button, to find a weather product.</native:text>

            @if ($recents !== [])
                <native:row class="w-full justify-between items-center">
                    <native:text class="text-lg font-bold text-theme-on-background">Recent searches</native:text>
                    <pressable @tap="clearRecent" ref="clear-recent">
                        <native:text class="text-sm font-semibold text-theme-primary p-2">Clear</native:text>
                    </pressable>
                </native:row>

                @foreach ($recents as $index => $recent)
                    <pressable @tap="useRecent({{ $index }})" ref="recent-{{ $index }}" class="w-full">
                        <native:text class="text-base text-theme-on-background p-2">{{ $this->recentLabel($recent) }}</native:text>
                    </pressable>
                    <native:divider></native:divider>
                @endforeach
            @endif
        @else
            @forelse ($results as $product)
                @if (filled($product['view_url'] ?? null))
                    <pressable @tap="open({{ $product['id'] }})" ref="product-{{ $product['id'] }}" class="w-full">
                        <native:column class="w-full gap-1 p-2">
                            <native:text class="text-base font-semibold text-theme-on-background">{{ $product['title'] }}</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">{{ $this->subtitle($product) }}</native:text>
                        </native:column>
                    </pressable>
                @else
                    <native:column class="w-full gap-1 p-2">
                        <native:text class="text-base font-semibold text-theme-on-background">{{ $product['title'] }}</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant">{{ $this->subtitle($product) }}</native:text>
                    </native:column>
                @endif
                <native:divider></native:divider>
            @empty
                <native:column class="w-full p-6 items-center gap-2">
                    <native:text class="text-lg font-semibold text-theme-on-background">No products match</native:text>
                    <native:text class="text-sm text-theme-on-surface-variant text-center">Try a different word or loosen a filter.</native:text>
                </native:column>
            @endforelse

            @if ($nextCursor !== null)
                <native:button label="Show more" variant="secondary" @tap="loadMore" ref="show-more" class="w-full" />
            @endif
        @endif
    </native:column>
</native:scroll-view>
