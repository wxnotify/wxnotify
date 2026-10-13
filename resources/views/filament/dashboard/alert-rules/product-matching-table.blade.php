@php
    use Filament\Support\View\Components\ToggleComponent;
    use Illuminate\Support\Arr;
    use function Filament\Support\get_component_color_classes;

    $statePath = $getStatePath();
    $rows = $getRows();
    $products = $getProducts();
    $locationModes = $getLocationModeValues();
    $locationOptions = $getLocationOptions();
    $locationLabels = collect($locationOptions)->mapWithKeys(fn (array $location): array => [$location['id'] => $location['label']])->all();
    $noLocationsMessage = $getNoLocationsMessage();
    $canCreateLocation = $canCreateLocation();
    $helperTexts = $getHelperTexts();
    $actionContext = ['schemaComponent' => $getKey()];
    $officeListId = $field::officeListId();
    // Disjoint class lists, so Alpine's object syntax can swap them without dropping a shared class.
    $onClasses = Arr::toCssClasses(['fi-toggle-on', ...get_component_color_classes(ToggleComponent::class, 'primary')]);
    $offClasses = Arr::toCssClasses(['fi-toggle-off', ...get_component_color_classes(ToggleComponent::class, 'gray')]);
@endphp

<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    {{--
        One Alpine scope per table holds what every row shares (each mode's helper text, the
        subscriber's locations) and row(), each row's scope: a row reads its own state with
        $wire.get() and writes it with deferred wire:model / $wire.set(..., false), so nothing here
        makes a request except the two modal actions and the switch of an SPC & WPC risk-level
        product.

        The scope captures the location list when it starts, so its wire:key changes with the
        list: adding a location from a row makes the morph replace the table and start a new
        scope that offers it (Alpine state is not re-read on a morph).
    --}}
    <div
        wire:key="{{ $statePath }}.locations.{{ md5(json_encode($locationOptions)) }}"
        x-data="{
            helpers: @js($helperTexts),
            locationModes: @js($locationModes),
            locations: @js($locationOptions),
            locationLabels: @js((object) $locationLabels),
            onClasses: @js($onClasses),
            offClasses: @js($offClasses),
            row(path) {
                return {
                    path,
                    open: false,
                    get on() { return !! this.$wire.get(path + '.enabled') },
                    get mode() { return this.$wire.get(path + '.matching_mode') },
                    get locationIds() { return (this.$wire.get(path + '.location_ids') ?? []).map(Number) },
                    toggle(live) { this.$wire.set(path + '.enabled', ! this.on, live) },
                    pickLocation(id, picked) {
                        let ids = this.locationIds.filter((other) => other !== id)

                        this.$wire.set(path + '.location_ids', picked ? [...ids, id] : ids, false)
                    },
                    locationSummary() {
                        let labels = this.locationIds.map((id) => this.locationLabels[id]).filter(Boolean)

                        return labels.length ? labels.join(', ') : 'Choose locations'
                    },
                    action(name, product) { this.$wire.mountAction(name, { product }, @js($actionContext)) },
                }
            },
        }"
        class="fi-alert-product-table overflow-x-auto"
    >
        @if ($shouldRenderOfficeList())
            <datalist id="{{ $officeListId }}">
                @foreach ($getOfficeOptions() as $code => $label)
                    <option value="{{ $code }}">{{ $label }}</option>
                @endforeach
            </datalist>
        @endif

        <table class="w-full table-auto text-start text-sm">
            <thead class="sr-only">
                <tr>
                    <th scope="col">On</th>
                    <th scope="col">Product</th>
                    <th scope="col">How it matches</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                @foreach ($rows as $index => $row)
                    @php
                        $product = $products->get((int) $row['product_id']);
                        $rowPath = "{$statePath}.{$index}";
                        $isOn = filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOL);
                        $mode = (string) ($row['matching_mode'] ?? '');
                        $selectedLocations = collect($row['location_ids'] ?? [])->map(fn ($id) => $locationLabels[(int) $id] ?? null)->filter()->implode(', ');
                        $zones = collect(is_array($row['county_zone_codes'] ?? null) ? $row['county_zone_codes'] : [])->implode(', ');
                        $refreshes = $switchRefreshesForm($product);
                    @endphp

                    <tr
                        wire:key="{{ $statePath }}.{{ $index }}.{{ $product->id }}"
                        data-product-id="{{ $product->id }}"
                        x-data="row(@js($rowPath))"
                        x-bind:class="{ 'opacity-50': ! on }"
                        @class(['align-top transition-opacity', 'opacity-50' => ! $isOn])
                    >
                        <td class="w-14 py-3 pe-3">
                            <button
                                type="button"
                                role="switch"
                                aria-label="Watch {{ $product->name }}"
                                aria-checked="{{ $isOn ? 'true' : 'false' }}"
                                x-bind:aria-checked="on ? 'true' : 'false'"
                                x-on:click="toggle(@js($refreshes))"
                                class="fi-toggle {{ $isOn ? $onClasses : $offClasses }}"
                                x-bind:class="{ [onClasses]: on, [offClasses]: ! on }"
                            >
                                <div>
                                    <div aria-hidden="true"></div>
                                    <div aria-hidden="true"></div>
                                </div>
                            </button>
                        </td>

                        <td class="py-3 pe-4">
                            <div class="font-medium text-gray-950 dark:text-white">{{ $product->name }}</div>
                            @if (filled($product->description))
                                <p class="text-gray-500 dark:text-gray-400">{{ $product->description }}</p>
                            @endif
                        </td>

                        <td class="w-full py-3 sm:w-80">
                            <div class="fi-input-wrp">
                                <div class="fi-input-wrp-content-ctn">
                                    <select
                                        class="fi-select-input"
                                        aria-label="How {{ $product->name }} matches"
                                        wire:model="{{ $rowPath }}.matching_mode"
                                    >
                                        @foreach ($modeOptionsFor($row) as $value => $label)
                                            <option value="{{ $value }}" @selected($value === $mode)>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div
                                x-show="locationModes.includes(mode)"
                                @if (! in_array($mode, $locationModes, true)) x-cloak @endif
                                x-on:click.outside="open = false"
                                x-on:keydown.escape="open = false"
                                class="relative mt-2"
                            >
                                <div class="flex gap-2">
                                    <button
                                        type="button"
                                        x-on:click="open = ! open"
                                        x-bind:aria-expanded="open"
                                        aria-label="Locations for {{ $product->name }}"
                                        class="fi-input-wrp min-w-0 flex-1 truncate px-3 py-1.5 text-start text-gray-950 dark:text-white"
                                        x-text="locationSummary()"
                                    >{{ $selectedLocations !== '' ? $selectedLocations : 'Choose locations' }}</button>

                                    @if ($canCreateLocation)
                                        <button
                                            type="button"
                                            title="Add a location"
                                            aria-label="Add a location for {{ $product->name }}"
                                            x-on:click="action('addLocation', {{ $product->id }})"
                                            class="fi-input-wrp flex items-center px-2 text-gray-500 dark:text-gray-400"
                                        >
                                            <x-filament::icon icon="heroicon-m-plus" class="size-5" />
                                        </button>
                                    @endif
                                </div>

                                {{-- The checkboxes exist only while the list is open: a subscriber with many
                                     locations would otherwise put all of them in every row. --}}
                                <template x-if="open">
                                    <div class="absolute inset-x-0 z-20 mt-1 max-h-60 overflow-y-auto rounded-lg bg-white p-1 shadow-lg ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
                                        <template x-for="location in locations" x-bind:key="location.id">
                                            <label class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 hover:bg-gray-50 dark:hover:bg-white/5">
                                                <input
                                                    type="checkbox"
                                                    class="fi-checkbox-input"
                                                    x-bind:checked="locationIds.includes(location.id)"
                                                    x-on:change="pickLocation(location.id, $event.target.checked)"
                                                />
                                                <span x-text="location.label"></span>
                                            </label>
                                        </template>
                                    </div>
                                </template>

                                @if (filled($noLocationsMessage))
                                    <p class="fi-fo-field-wrp-helper-text mt-1 text-gray-500 dark:text-gray-400">{{ $noLocationsMessage }}</p>
                                @endif
                            </div>

                            <div
                                x-show="mode === 'wfo'"
                                @if ($mode !== 'wfo') x-cloak @endif
                                class="fi-input-wrp mt-2"
                            >
                                <div class="fi-input-wrp-content-ctn">
                                    <input
                                        type="text"
                                        list="{{ $officeListId }}"
                                        maxlength="4"
                                        autocomplete="off"
                                        placeholder="NWS office, e.g. OKX"
                                        aria-label="NWS office for {{ $product->name }}"
                                        class="fi-input"
                                        wire:model="{{ $rowPath }}.wfo"
                                    />
                                </div>
                            </div>

                            <div
                                x-show="mode === 'county_zone'"
                                @if ($mode !== 'county_zone') x-cloak @endif
                                class="mt-2 flex items-start gap-2"
                            >
                                <span class="min-w-0 flex-1 break-words text-gray-700 dark:text-gray-300">{{ $zones !== '' ? $zones : 'No counties or zones chosen' }}</span>
                                <button
                                    type="button"
                                    x-on:click="action('chooseZones', {{ $product->id }})"
                                    class="fi-link fi-size-sm shrink-0 font-semibold text-primary-600 dark:text-primary-400"
                                >
                                    Choose zones
                                </button>
                            </div>

                            <p class="mt-1 text-gray-500 dark:text-gray-400" x-text="helpers[mode] ?? ''">{{ $helperTexts[$mode] ?? '' }}</p>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</x-dynamic-component>
