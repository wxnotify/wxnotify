{{--
    App-side override of jbuitt/filament-toggle-list's option row layout —
    the package ships no CSS of its own for `.fi-fo-toggle-list-option`, so
    the toggle button and text stack in plain block order by default. Adding
    `flex items-start gap-3` here (and `flex-1 min-w-0` on the text column)
    puts the toggle inline with the title, with the description still
    wrapping underneath. Everything else is untouched from the package's own
    resources/views/components/toggle-list.blade.php — only the two classed
    <div>s inside the @forelse loop changed. Copy that file again if the
    package ever changes this markup.
--}}
@php
    use Filament\Support\Enums\GridDirection;
    use Filament\Support\Facades\FilamentAsset;
    use Filament\Support\View\Components\ToggleComponent;
    use function Filament\Support\generate_icon_html;
    use function Filament\Support\get_component_color_classes;

    $statePath = $getStatePath();
    $state = (array) ($getState() ?? []);
    $options = $getOptions();
    $isDisabled = $isDisabled();
    $isSearchable = $isSearchable();
    $isBulkToggleable = $isBulkToggleable();
    $isHtmlAllowed = $isHtmlAllowed();
    $columns = $getColumns();
    $gridDirection = $getGridDirection();
    $onColor = $getOnColor();
    $offColor = $getOffColor();
    $onClasses = \Illuminate\Support\Js::from(\Illuminate\Support\Arr::toCssClasses([
        'fi-toggle-on', ...get_component_color_classes(ToggleComponent::class, $onColor),
    ]));
    $offClasses = \Illuminate\Support\Js::from(\Illuminate\Support\Arr::toCssClasses([
        'fi-toggle-off', ...get_component_color_classes(ToggleComponent::class, $offColor),
    ]));
    $offIconHtml = generate_icon_html($getOffIcon(), size: \Filament\Support\Enums\IconSize::ExtraSmall)?->toHtml();
    $onIconHtml = generate_icon_html($getOnIcon(), size: \Filament\Support\Enums\IconSize::ExtraSmall)?->toHtml();
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        role="group"
        x-load
        x-load-src="{{ FilamentAsset::getAlpineComponentSrc('toggle-list', 'jbuitt/filament-toggle-list') }}"
        x-data="toggleListFormComponent({ livewireId: '{{ $this->getId() }}' })"
        class="fi-fo-toggle-list"
    >
        @if (! $isDisabled)
            @if ($isSearchable)
                <div class="fi-input-wrp fi-fo-toggle-list-search-input-wrp">
                    <div class="fi-input-wrp-prefix fi-input-wrp-prefix-has-content fi-inline">
                        {!! generate_icon_html(\Filament\Support\Icons\Heroicon::MagnifyingGlass)->toHtml() !!}
                    </div>
                    <div class="fi-input-wrp-content-ctn">
                        <input
                            type="search"
                            aria-label="{{ $getSearchPrompt() }}"
                            placeholder="{{ $getSearchPrompt() }}"
                            x-model.debounce.{{ $getSearchDebounce() }}="search"
                            class="fi-input fi-input-has-inline-prefix"
                        />
                    </div>
                </div>
            @endif

            @if ($isBulkToggleable && count($options))
                <div x-cloak class="fi-fo-toggle-list-actions">
                    <button type="button" x-show="! areAllTogglesOn" x-on:click="toggleAllToggles()" class="fi-link fi-size-sm">
                        {{ $getEnableAllLabel() }}
                    </button>
                    <button type="button" x-show="areAllTogglesOn" x-on:click="toggleAllToggles()" class="fi-link fi-size-sm">
                        {{ $getDisableAllLabel() }}
                    </button>
                </div>
            @endif
        @endif

        <div
            {{
                (new \Filament\Support\View\ComponentAttributeBag())
                    ->grid($columns, $gridDirection)
                    ->class(['fi-fo-toggle-list-options'])
            }}
            @if ($isSearchable) x-show="visibleToggleListOptions.length" @endif
        >
            @forelse ($options as $value => $label)
                @php
                    $isChecked = in_array((string) $value, $state, true);
                    $isThisOptionDisabled = $isDisabled || $isOptionDisabled($value, $label);
                @endphp
                <div
                    wire:key="{{ $getLivewireKey() }}.options.{{ $value }}"
                    class="fi-fo-toggle-list-option-ctn"
                    @if ($isSearchable)
                        x-show="
                            $el.querySelector('.fi-fo-toggle-list-option-label')?.innerText.toLowerCase().includes(search.toLowerCase()) ||
                            $el.querySelector('.fi-fo-toggle-list-option-description')?.innerText.toLowerCase().includes(search.toLowerCase())
                        "
                    @endif
                >
                    <div
                        class="fi-fo-toggle-list-option flex items-start gap-3"
                        x-data="{ state: {{ $isChecked ? 'true' : 'false' }}, disabled: {{ $isThisOptionDisabled ? 'true' : 'false' }} }"
                        x-on:click="if (! disabled) { state = ! state; $refs.input.checked = state; $refs.input.dispatchEvent(new Event('change', { bubbles: true })); }"
                    >
                        <input
                            type="checkbox"
                            aria-hidden="true"
                            tabindex="-1"
                            class="fi-fo-toggle-list-option-input"
                            style="position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0, 0, 0, 0); white-space: nowrap; border-width: 0;"
                            value="{{ $value }}"
                            x-ref="input"
                            x-on:change="state = $event.target.checked"
                            @disabled($isThisOptionDisabled)
                            wire:loading.attr="disabled"
                            {{ $applyStateBindingModifiers('wire:model') }}="{{ $statePath }}"
                        />
                        <button
                            type="button"
                            role="switch"
                            tabindex="0"
                            x-bind:aria-checked="state ? 'true' : 'false'"
                            x-bind:class="state ? {{ $onClasses }} : {{ $offClasses }}"
                            @disabled($isThisOptionDisabled)
                            class="fi-toggle shrink-0"
                        >
                            <div>
                                <div aria-hidden="true">{!! $offIconHtml !!}</div>
                                <div aria-hidden="true">{!! $onIconHtml !!}</div>
                            </div>
                        </button>
                        <div class="fi-fo-toggle-list-option-text flex-1 min-w-0">
                            <span class="fi-fo-toggle-list-option-label">
                                @if ($isHtmlAllowed) {!! $label !!} @else {{ $label }} @endif
                            </span>
                            @if ($hasDescription($value))
                                <p class="fi-fo-toggle-list-option-description">{{ $getDescription($value) }}</p>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div wire:key="{{ $getLivewireKey() }}.empty"></div>
            @endforelse
        </div>

        @if ($isSearchable)
            <div x-cloak x-show="search && ! visibleToggleListOptions.length" role="status" aria-live="polite" class="fi-fo-toggle-list-no-search-results-message">
                {{ $getNoSearchResultsMessage() }}
            </div>
        @endif
    </div>
</x-dynamic-component>
