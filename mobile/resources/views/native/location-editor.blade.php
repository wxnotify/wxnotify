{{-- Un-prefixed on purpose: native:validate does not know <pressable>, <select> or <outlined-text-input>, nor @change on <select> (a bare tag compiles the same). Handler arguments go through {{ }} so the validator skips them. --}}

<native:top-bar title="{{ $locationId === null ? 'New location' : 'Edit location' }}" show-navigation-icon="true"></native:top-bar>

<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-4">
        {{-- Why the editor could not open; once it is open, feedback shows beside the buttons at the bottom. --}}
        @if ($banner && ! $ready)
            <native:text class="text-sm text-theme-destructive">{{ $banner }}</native:text>
        @endif

        @if ($canRetry)
            <native:button label="Try again" variant="secondary" @tap="load" ref="retry-location" class="w-full" />
        @endif

        @if ($ready)
            <outlined-text-input label="Name this location" native:model="draft.label" ref="field-label" />
            @isset($errors['label'])
                <native:text class="text-sm text-theme-destructive">{{ $errors['label'] }}</native:text>
            @endisset

            @if ($this->isMobile())
                @if (($location['source'] ?? null) === 'app')
                    <native:text class="text-sm text-theme-on-surface-variant">
                        {{ ($location['linked'] ?? false) ? 'Kept current by a phone running WxNotify. Turn location sharing on or off in Account.' : 'Not linked to a phone. Turn on location sharing in the app on a phone to link it again.' }}
                    </native:text>
                @else
                    <native:text class="text-sm text-theme-on-surface-variant">Tracked by the Traccar device {{ $location['device_name'] ?? '' }}. Change the device on wxnotify.com.</native:text>
                @endif

                @if (filled($location['address'] ?? null))
                    <native:text class="text-sm text-theme-on-surface-variant">Last known address: {{ $location['address'] }}</native:text>
                @endif

                <select label="Update every" :options="$this->intervalLabels()" :value="$this->selectedIntervalLabel()" @change="pickInterval" ref="field-interval" />
                @isset($errors['update_interval_minutes'])
                    <native:text class="text-sm text-theme-destructive">{{ $errors['update_interval_minutes'] }}</native:text>
                @endisset
            @else
                <native:text class="text-lg font-bold text-theme-on-background">Where is it?</native:text>

                @if (filled($draft['lat']))
                    <native:column class="w-full p-3 gap-1 bg-theme-surface">
                        <native:text class="text-base text-theme-on-background">{{ filled($draft['address']) ? $draft['address'] : 'Position chosen' }}</native:text>
                        <native:text class="text-sm text-theme-on-surface-variant">{{ $draft['lat'] }}, {{ $draft['lng'] }}</native:text>
                    </native:column>
                @endif

                <native:button label="Use my current position" variant="secondary" @tap="useCurrentPosition" ref="use-position" class="w-full" />
                @if ($positionMessage)
                    <native:text class="text-sm text-theme-on-surface-variant">{{ $positionMessage }}</native:text>
                @endif
                @if ($permissionDenied)
                    <native:button label="Open settings" variant="secondary" @tap="openSettings" ref="open-settings" class="w-full" />
                @endif

                <native:text class="text-base font-semibold text-theme-on-background">Search for an address</native:text>
                <outlined-text-input label="Address, city or place" native:model="placeQuery" ref="field-place-query" />
                <native:button label="Search" variant="secondary" @tap="searchPlaces" ref="search-places" class="w-full" />
                @if ($placeMessage)
                    <native:text class="text-sm text-theme-on-surface-variant">{{ $placeMessage }}</native:text>
                @endif
                @if ($placeSearch !== null && $placeResults !== [])
                    <native:text class="text-sm text-theme-on-surface-variant">Results for "{{ $placeSearch }}"</native:text>
                @endif
                @foreach ($placeResults as $index => $place)
                    <pressable @tap="choosePlace({{ $index }})" ref="place-{{ $index }}" class="w-full">
                        <native:text class="text-base text-theme-on-background p-2">{{ $place['label'] }}</native:text>
                    </pressable>
                @endforeach

                @isset($errors['position'])
                    <native:text class="text-sm text-theme-destructive">{{ $errors['position'] }}</native:text>
                @endisset

                <select label="Timezone" :options="$this->timezoneLabels()" :value="$this->selectedTimezoneLabel()" @change="pickTimezone" ref="field-timezone" />
                <native:text class="text-sm text-theme-on-surface-variant">{{ $locationId === null ? 'Automatic sets the timezone from the position when you save.' : 'The timezone stays as it is unless you choose another, even when you move the location.' }}</native:text>
                @isset($errors['timezone'])
                    <native:text class="text-sm text-theme-destructive">{{ $errors['timezone'] }}</native:text>
                @endisset
            @endif

            {{-- The save/delete feedback and the confirmations sit right above the buttons that lead to them, where the subscriber is when they appear. --}}
            <native:column ref="location-actions" class="w-full gap-3">
                @if ($banner)
                    <native:text ref="location-feedback" class="text-sm text-theme-destructive">{{ $banner }}</native:text>
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
                        <native:text class="text-base text-theme-on-background">Delete this location? This can't be undone.</native:text>
                        <native:button label="Yes, delete" @tap="delete" class="w-full" />
                        <native:button label="Keep location" variant="secondary" @tap="cancelDelete" class="w-full" />
                    </native:column>
                @endif

                <native:button label="Save location" @tap="save" ref="save-location" class="w-full" />

                @if ($locationId !== null && ! $confirmingDelete)
                    <native:button label="Delete location" variant="secondary" @tap="askDelete" ref="delete-location" class="w-full" />
                @endif
            </native:column>
        @endif
    </native:column>
</native:scroll-view>
