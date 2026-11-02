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
    $offersCustomSubjects = $offersCustomSubjects();
    $actionContext = ['schemaComponent' => $getKey()];
    $officeListId = $field::officeListId();
    // Disjoint class lists, so Alpine's object syntax can swap them without dropping a shared class.
    $onClasses = Arr::toCssClasses(['fi-toggle-on', ...get_component_color_classes(ToggleComponent::class, 'primary')]);
    $offClasses = Arr::toCssClasses(['fi-toggle-off', ...get_component_color_classes(ToggleComponent::class, 'gray')]);
    $allOn = $rows !== [] && collect($rows)->every(fn (array $row): bool => filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOL));
    $categoryKey = $getProductCategory()->value;
    $categoryLabel = $getProductCategory()->label();
    // What Enable all writes: each row's state path, and whether its switch refreshes the form.
    $bulkRows = collect($rows)
        ->map(fn (array $row, int|string $index): array => [
            'path' => "{$statePath}.{$index}",
            'refreshes' => $switchRefreshesForm($products->get((int) $row['product_id'])),
        ])
        ->values()
        ->all();
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

        Search and Enable all are this scope too, so neither makes a request. Search hides a row
        (x-show) whose data-search text lacks the typed text; the row keeps its state, so it still
        saves. Enable all writes every row's `enabled` with a deferred $wire.set(), and reads ON
        exactly when every row of this table is on. A bulk change that flips an SPC & WPC
        risk-level product asks for ONE $wire.$refresh() afterwards (the deferred changes travel
        with it), so the server-rendered risk-level checklist matches what is now switched on.

        A paid subscriber's switched-on row shows its subject. Its own flag (subjectOpen, not the
        location list's open) reveals the text input, which exists only while revealed on a
        switched-on row; the deferred wire:model keeps what was typed when the row is switched
        off and on.
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
            rows: @js($bulkRows),
            search: '',
            matches(text) {
                let needle = this.search.trim().toLowerCase()

                return needle === '' || text.includes(needle)
            },
            get noMatch() {
                if (this.search.trim() === '') {
                    return false
                }

                return ! [...(this.$root?.querySelectorAll('tr[data-search]') ?? [])].some((tr) => this.matches(tr.dataset.search))
            },
            isOn(row) { return !! this.$wire.get(row.path + '.enabled') },
            get allOn() { return this.rows.length > 0 && this.rows.every((row) => this.isOn(row)) },
            toggleAll() {
                let on = ! this.allOn
                let changed = this.rows.filter((row) => this.isOn(row) !== on)

                changed.forEach((row) => this.$wire.set(row.path + '.enabled', on, false))

                if (changed.some((row) => row.refreshes)) {
                    this.$wire.$refresh()
                }
            },
            row(path) {
                return {
                    path,
                    open: false,
                    subjectOpen: false,
                    get on() { return !! this.$wire.get(path + '.enabled') },
                    get subject() { return String(this.$wire.get(path + '.custom_subject_template') ?? '').trim() },
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

        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <div class="fi-input-wrp w-full sm:max-w-xs">
                <div class="fi-input-wrp-content-ctn">
                    <input
                        type="search"
                        class="fi-input"
                        autocomplete="off"
                        placeholder="Search {{ $categoryLabel }}"
                        aria-label="Search {{ $categoryLabel }} products"
                        data-category-search="{{ $categoryKey }}"
                        x-model="search"
                        x-on:keydown.enter.prevent
                    />
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button
                    type="button"
                    role="switch"
                    id="{{ $statePath }}.enable-all"
                    aria-label="Enable all {{ $categoryLabel }} products"
                    data-enable-all="{{ $categoryKey }}"
                    aria-checked="{{ $allOn ? 'true' : 'false' }}"
                    x-bind:aria-checked="allOn ? 'true' : 'false'"
                    x-on:click="toggleAll()"
                    class="fi-toggle {{ $allOn ? $onClasses : $offClasses }}"
                    x-bind:class="{ [onClasses]: allOn, [offClasses]: ! allOn }"
                >
                    <div>
                        <div aria-hidden="true"></div>
                        <div aria-hidden="true"></div>
                    </div>
                </button>
                <label for="{{ $statePath }}.enable-all" class="text-sm font-medium text-gray-950 dark:text-white">Enable all</label>
            </div>
        </div>

        <p x-show="noMatch" x-cloak class="py-3 text-sm text-gray-500 dark:text-gray-400">No products match your search.</p>

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
                        data-search="{{ mb_strtolower(trim($product->name.' '.$product->description)) }}"
                        x-show="matches($el.dataset.search)"
                        x-data="row(@js($rowPath))"
                        class="align-top"
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
                            <div class="flex items-center gap-1.5">
                                <span class="font-medium text-gray-950 dark:text-white">{{ $product->name }}</span>
                                @if ($hasExample($product))
                                    <button
                                        type="button"
                                        title="Show example"
                                        aria-label="Show example of {{ $product->name }}"
                                        x-on:click="action('showExample', {{ $product->id }})"
                                        class="shrink-0 text-gray-400 hover:text-primary-600 dark:text-gray-500 dark:hover:text-primary-400"
                                    >
                                        <x-filament::icon icon="heroicon-o-document-text" class="size-5" />
                                    </button>
                                @endif
                            </div>
                            @if (filled($product->description))
                                <p class="text-gray-500 dark:text-gray-400">{{ $product->description }}</p>
                            @endif

                            @if ($offersCustomSubjects)
                                @php
                                    $subject = is_string($row['custom_subject_template'] ?? null) ? trim($row['custom_subject_template']) : '';
                                @endphp

                                <div x-show="on" @if (! $isOn) x-cloak @endif class="mt-2">
                                    <div class="flex min-w-0 items-center gap-2">
                                        <span
                                            x-show="subject === ''"
                                            @if ($subject !== '') x-cloak @endif
                                            class="text-gray-500 dark:text-gray-400"
                                        >Uses the default subject</span>
                                        <span
                                            x-show="subject !== ''"
                                            @if ($subject === '') x-cloak @endif
                                            x-text="subject"
                                            class="min-w-0 truncate text-gray-700 dark:text-gray-300"
                                        >{{ $subject }}</span>
                                        <button
                                            type="button"
                                            x-on:click="subjectOpen = ! subjectOpen"
                                            x-bind:aria-expanded="subjectOpen"
                                            aria-label="{{ $subject === '' ? 'Custom subject' : 'Edit subject' }} for {{ $product->name }}"
                                            x-bind:aria-label="(subject === '' ? 'Custom subject' : 'Edit subject') + ' for ' + @js($product->name)"
                                            x-text="subject === '' ? 'Custom subject' : 'Edit'"
                                            class="fi-link fi-size-sm shrink-0 font-semibold text-primary-600 dark:text-primary-400"
                                        >{{ $subject === '' ? 'Custom subject' : 'Edit' }}</button>
                                    </div>

                                    <template x-if="on && subjectOpen">
                                        <div class="mt-2">
                                            <div class="fi-input-wrp">
                                                <div class="fi-input-wrp-content-ctn">
                                                    <input
                                                        type="text"
                                                        maxlength="255"
                                                        autocomplete="off"
                                                        aria-label="Custom subject for {{ $product->name }}"
                                                        class="fi-input"
                                                        wire:model="{{ $rowPath }}.custom_subject_template"
                                                        x-on:keydown.enter.prevent
                                                    />
                                                </div>
                                            </div>
                                            <p class="mt-1 text-gray-500 dark:text-gray-400">{{ $subjectHelperTextFor($product) }}</p>
                                        </div>
                                    </template>
                                </div>
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
                                    aria-label="Choose zones for {{ $product->name }}"
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
