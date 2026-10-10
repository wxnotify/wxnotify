@php
    $presenter = $ready ? $this->presenter() : null;
    $category = $ready ? $draft['category'] : '';
    $errorKeys = ['products' => 'product_ids', 'locations' => 'location_ids', 'location' => 'location_id'];
    $isProductCategory = in_array($category, ['weather_alerts', 'spc_wpc'], true);
    // A handler's string argument as a PHP literal ('OKX'): written through {!! !!} so native:validate skips the value.
    $quote = fn (string $value): string => var_export($value, true);
@endphp

{{-- Un-prefixed on purpose: native:validate does not know <pressable>, <checkbox>, <select>, <date-picker>, <outlined-text-input> or <accordion>, nor @change on them (a bare tag compiles the same). Handler arguments go through {{ }} / {!! !!} so the validator skips them. --}}

<native:top-bar title="{{ $ruleId === null ? 'New alert rule' : 'Edit alert rule' }}" show-navigation-icon="true"></native:top-bar>

<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-4">
        {{-- Why the form could not open; once it is open, feedback shows beside the buttons at the bottom. --}}
        @if ($banner && ! $ready)
            <native:text class="text-sm text-theme-destructive">{{ $banner }}</native:text>
        @endif

        @if ($canRetry)
            <native:button label="Try again" variant="secondary" @tap="load" ref="retry-editor" class="w-full" />
        @endif

        @if ($ready)
            <native:text class="text-lg font-bold text-theme-on-background">What kind of alert?</native:text>
            @foreach ($presenter->categories() as $choice)
                <pressable @tap="chooseCategory({!! $quote($choice['value']) !!})" ref="category-{{ $choice['value'] }}" class="w-full">
                    <native:column class="w-full p-3 gap-1 bg-theme-surface">
                        <native:text class="text-base font-semibold text-theme-on-background">{{ $choice['label'] }}{{ $category === $choice['value'] ? '  ✓' : '' }}</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant">{{ $choice['description'] }}</native:text>
                    </native:column>
                </pressable>
            @endforeach
            @isset($errors['category'])
                <native:text class="text-sm text-theme-destructive">{{ $errors['category'] }}</native:text>
            @endisset

            @if ($category !== '')
                <native:text class="text-lg font-bold text-theme-on-background">Set it up</native:text>

                @foreach ($presenter->fields($draft) as $field)
                    @switch($field)
                        @case('products')
                            @foreach ($presenter->productGroups($draft) as $group)
                                @php($open = $loop->first || count(array_intersect(array_column($group['products'], 'id'), $draft['product_ids'])) > 0)
                                <accordion :expanded="$open">
                                    <accordion-header>
                                        <native:text class="text-base font-semibold text-theme-on-background">{{ $group['label'] }}</native:text>
                                    </accordion-header>
                                    <accordion-content>
                                        <native:column class="w-full gap-1">
                                            @foreach ($group['products'] as $product)
                                                <checkbox :value="in_array($product['id'], $draft['product_ids'], true)" label="{{ $product['name'] }}" @change="setProduct({{ $product['id'] }})" ref="product-{{ $product['id'] }}" />
                                            @endforeach
                                        </native:column>
                                    </accordion-content>
                                </accordion>
                            @endforeach
                            @break

                        @case('risk_levels')
                            <native:text class="text-base font-semibold text-theme-on-background">Risk levels</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">Leave all unchecked to receive every risk level for the products above.</native:text>
                            @foreach ($presenter->riskLevelChoices($draft) as $level)
                                <checkbox :value="in_array($level, $draft['risk_levels'], true)" label="{{ $level }}" @change="setRiskLevel({!! $quote($level) !!})" ref="risk-{{ $level }}" />
                            @endforeach
                            @break

                        @case('matching_mode')
                            <native:text class="text-base font-semibold text-theme-on-background">Matching mode</native:text>
                            @if ($unlistedMode = $this->unlistedModeLabel())
                                <native:text class="text-sm text-theme-on-surface-variant">Currently: {{ $unlistedMode }}</native:text>
                            @endif
                            @foreach ($presenter->allowedModes($draft) as $mode)
                                <pressable @tap="chooseMode({!! $quote($mode['value']) !!})" ref="mode-{{ $mode['value'] }}" class="w-full">
                                    <native:column class="w-full p-3 gap-1 bg-theme-surface">
                                        <native:text class="text-base text-theme-on-background">{{ $mode['label'] }}{{ $draft['matching_mode'] === $mode['value'] ? '  ✓' : '' }}</native:text>
                                        <native:text class="text-sm text-theme-on-surface-variant">{{ $mode['description'] }}</native:text>
                                    </native:column>
                                </pressable>
                            @endforeach
                            @break

                        @case('locations')
                            <native:text class="text-base font-semibold text-theme-on-background">Locations</native:text>
                            @forelse ($options['locations'] ?? [] as $location)
                                <checkbox :value="in_array($location['id'], $draft['location_ids'], true)" label="{{ $location['label'] }}" @change="setLocation({{ $location['id'] }})" ref="location-{{ $location['id'] }}" />
                            @empty
                                <native:text class="text-sm text-theme-on-surface-variant">You have no saved locations yet.</native:text>
                            @endforelse
                            @if ($options['can_create_location'] ?? false)
                                <native:button label="Add a location" variant="secondary" @tap="addLocation" class="w-full" />
                            @endif
                            @break

                        @case('wfo')
                            <native:text class="text-base font-semibold text-theme-on-background">NWS office</native:text>
                            <native:text class="text-sm text-theme-on-surface-variant">{{ $draft['wfo'] === '' ? 'No office chosen yet.' : 'Chosen: '.$draft['wfo'] }}</native:text>
                            <outlined-text-input label="Search offices by city or code" native:model="officeQuery" ref="office-query" />
                            <native:button label="Search offices" variant="secondary" @tap="searchOffices" class="w-full" />
                            @if ($pickerMessage)
                                <native:text class="text-sm text-theme-destructive">{{ $pickerMessage }}</native:text>
                            @endif
                            @foreach ($officeResults as $office)
                                <pressable @tap="chooseOffice({!! $quote($office['code']) !!})" ref="office-{{ $office['code'] }}" class="w-full">
                                    <native:text class="text-base text-theme-on-background p-2">{{ $office['label'] }}</native:text>
                                </pressable>
                            @endforeach
                            @break

                        @case('county_zone_codes')
                            <native:text class="text-base font-semibold text-theme-on-background">Counties and zones</native:text>
                            @foreach ($draft['county_zone_codes'] as $code)
                                <checkbox :value="true" label="{{ $code }}" @change="setZone({!! $quote($code) !!})" ref="zone-{{ $code }}" />
                            @endforeach
                            <outlined-text-input label="State (two letters)" native:model="zoneState" ref="zone-state" />
                            <outlined-text-input label="County or zone name" native:model="zoneQuery" ref="zone-query" />
                            <native:button label="Search zones" variant="secondary" @tap="searchZones" class="w-full" />
                            @if ($pickerMessage)
                                <native:text class="text-sm text-theme-destructive">{{ $pickerMessage }}</native:text>
                            @endif
                            @foreach ($zoneResults as $zone)
                                @unless (in_array($zone['code'], $draft['county_zone_codes'], true))
                                    <checkbox :value="false" label="{{ $zone['label'] }}" @change="setZone({!! $quote($zone['code']) !!})" ref="zone-{{ $zone['code'] }}" />
                                @endunless
                            @endforeach
                            @if ($zoneNextAfter)
                                <native:button label="More results" variant="secondary" @tap="moreZones" class="w-full" />
                            @endif
                            @break

                        @case('variable')
                        @case('direction')
                        @case('timeframe')
                            <select label="{{ ['variable' => 'Variable', 'direction' => 'Direction', 'timeframe' => 'Timeframe'][$field] }}" :options="$this->selectLabels($field)" :value="$this->selectedLabel($field)" @change="pick({!! $quote($field) !!})" ref="field-{{ $field }}" />
                            @break

                        @case('threshold_value')
                            <outlined-text-input label="Value{{ $presenter->unitLabel($draft) ? ' ('.$presenter->unitLabel($draft).')' : '' }}" native:model="draft.threshold_value" keyboard="decimal" ref="field-threshold_value" />
                            @break

                        @case('location')
                            <select label="Location" :options="$this->selectLabels('location_id')" :value="$this->selectedLabel('location_id')" @change="pick({!! $quote('location_id') !!})" ref="field-location" />
                            @if (($options['locations'] ?? []) === [])
                                <native:text class="text-sm text-theme-on-surface-variant">You have no saved locations yet.</native:text>
                            @endif
                            @if ($options['can_create_location'] ?? false)
                                <native:button label="Add a location" variant="secondary" @tap="addLocation" class="w-full" />
                            @endif
                            @break

                        @case('delivery_time')
                            <date-picker mode="time" label="Delivery time (when we check and deliver this alert each day)" :value="$draft['delivery_time']" @change="setTime({!! $quote('delivery_time') !!})" ref="field-delivery_time" />
                            @break

                        @case('radius')
                            <outlined-text-input label="Alert radius ({{ $presenter->radiusUnit() }})" native:model="draft.radius" keyboard="number" ref="field-radius" />
                            <native:text class="text-sm text-theme-on-surface-variant">From {{ $presenter->radiusBounds()['min'] }} to {{ $presenter->radiusBounds()['max'] }} {{ $presenter->radiusUnit() }}.</native:text>
                            @break
                    @endswitch

                    @php($errorKey = $errorKeys[$field] ?? $field)
                    @isset($errors[$errorKey])
                        <native:text class="text-sm text-theme-destructive">{{ $errors[$errorKey] }}</native:text>
                    @endisset
                @endforeach
                @if (($errors['location_id'] ?? null) && ! in_array('location', $presenter->fields($draft), true))
                    <native:text class="text-sm text-theme-destructive">{{ $errors['location_id'] }}</native:text>
                @endif
            @endif

            <native:text class="text-lg font-bold text-theme-on-background">Notify me on</native:text>
            @forelse ($options['channels'] ?? [] as $channel)
                <checkbox :value="in_array($channel['id'], $draft['channel_ids'], true)" label="{{ $channel['label'] }} ({{ $channel['type_label'] }}){{ $channel['verified'] ? '' : ' (unverified)' }}" @change="setChannel({{ $channel['id'] }})" ref="channel-{{ $channel['id'] }}" />
            @empty
                <native:text class="text-sm text-theme-on-surface-variant">You have no channels yet. Turn on push notifications in Account, or add a channel on wxnotify.com.</native:text>
                <native:button label="Open Account" variant="secondary" @tap="openAccount" class="w-full" />
            @endforelse
            @isset($errors['channel_ids'])
                <native:text class="text-sm text-theme-destructive">{{ $errors['channel_ids'] }}</native:text>
            @endisset

            <native:text class="text-lg font-bold text-theme-on-background">Finishing touches</native:text>
            <outlined-text-input label="Name this alert (optional)" native:model="draft.name" ref="field-name" />
            <native:text class="text-sm text-theme-on-surface-variant">Leave blank and we'll generate one from your settings.</native:text>
            @isset($errors['name'])
                <native:text class="text-sm text-theme-destructive">{{ $errors['name'] }}</native:text>
            @endisset

            <date-picker mode="time" label="Quiet hours start (optional)" :value="$draft['quiet_hours_start']" @change="setTime({!! $quote('quiet_hours_start') !!})" ref="field-quiet_hours_start" />
            <date-picker mode="time" label="Quiet hours end (optional)" :value="$draft['quiet_hours_end']" @change="setTime({!! $quote('quiet_hours_end') !!})" ref="field-quiet_hours_end" />
            @if ($draft['quiet_hours_start'] !== '' || $draft['quiet_hours_end'] !== '')
                <native:button label="Clear quiet hours" variant="secondary" @tap="clearQuietHours" class="w-full" />
            @endif
            @isset($errors['quiet_hours_start'])
                <native:text class="text-sm text-theme-destructive">{{ $errors['quiet_hours_start'] }}</native:text>
            @endisset
            @isset($errors['quiet_hours_end'])
                <native:text class="text-sm text-theme-destructive">{{ $errors['quiet_hours_end'] }}</native:text>
            @endisset

            @if ($presenter->canCustomizeMessages())
                <select label="Message length" :options="$this->selectLabels('message_body_style')" :value="$this->selectedLabel('message_body_style')" @change="pick({!! $quote('message_body_style') !!})" ref="field-message_body_style" />
                <outlined-text-input label="Custom subject line (optional)" native:model="draft.custom_subject_template" ref="field-custom_subject_template" />
                <native:text class="text-sm text-theme-on-surface-variant">Placeholders: {product}, {location}, {category}.</native:text>
                @if ($isProductCategory)
                    <select label="Message template (optional)" :options="$this->selectLabels('alert_template_id')" :value="$this->selectedLabel('alert_template_id')" @change="pick({!! $quote('alert_template_id') !!})" ref="field-alert_template_id" />
                @endif
                @foreach (['message_body_style', 'custom_subject_template', 'alert_template_id'] as $messageField)
                    @isset($errors[$messageField])
                        <native:text class="text-sm text-theme-destructive">{{ $errors[$messageField] }}</native:text>
                    @endisset
                @endforeach
            @endif

            {{-- The save/delete feedback and the confirmations sit right above the buttons that lead to them, where the subscriber is when they appear. --}}
            <native:column ref="editor-actions" class="w-full gap-3">
                @if ($banner)
                    <native:text ref="editor-feedback" class="text-sm text-theme-destructive">{{ $banner }}</native:text>
                @endif

                @if ($confirmingDiscard)
                    <native:column class="w-full p-3 gap-2 bg-theme-surface">
                        <native:text class="text-base text-theme-on-background">Discard your changes?</native:text>
                        <native:button label="Discard changes" @tap="discard" class="w-full" />
                        <native:button label="Keep editing" variant="secondary" @tap="keepEditing" class="w-full" />
                    </native:column>
                @endif

                @if ($confirmingDelete)
                    <native:column class="w-full p-3 gap-2 bg-theme-surface">
                        <native:text class="text-base text-theme-on-background">Delete this alert rule? This can't be undone.</native:text>
                        <native:button label="Yes, delete" @tap="delete" class="w-full" />
                        <native:button label="Keep rule" variant="secondary" @tap="cancelDelete" class="w-full" />
                    </native:column>
                @endif

                <native:button label="Save rule" @tap="save" ref="save-rule" class="w-full" />

                @if ($ruleId !== null && ! $confirmingDelete)
                    <native:button label="Delete rule" variant="secondary" @tap="askDelete" ref="delete-rule" class="w-full" />
                @endif
            </native:column>
        @endif
    </native:column>
</native:scroll-view>
