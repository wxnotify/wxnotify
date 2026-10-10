{{-- Un-prefixed on purpose: native:validate does not know <pressable> or <outlined-text-input> (a bare tag compiles the same). Handler arguments go through {{ }} so the validator skips them. --}}

<native:top-bar title="Add channel" show-navigation-icon="true"></native:top-bar>

<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-4">
        @if ($banner)
            <native:text class="text-sm text-theme-destructive">{{ $banner }}</native:text>
        @endif

        @if ($canRetry)
            <native:button label="Try again" variant="secondary" @tap="load" ref="retry-types" class="w-full" />
        @endif

        @if ($ready && $selected === null)
            <native:text class="text-base text-theme-on-surface-variant">Where should your alerts be sent?</native:text>

            @foreach ($types as $index => $type)
                <pressable @tap="pickType({{ $index }})" ref="type-{{ $type['slug'] }}" class="w-full">
                    <native:column class="w-full gap-1 p-2">
                        <native:text class="text-base font-semibold {{ $type['can_add'] ? 'text-theme-on-background' : 'text-theme-on-surface-variant' }}">{{ $type['label'] }}</native:text>
                        @unless ($type['can_add'])
                            <native:text class="text-sm text-theme-on-surface-variant">Not available on your plan right now</native:text>
                        @endunless
                    </native:column>
                </pressable>
                <native:divider></native:divider>
            @endforeach

            @if ($feedback)
                <native:text class="text-sm text-theme-destructive">{{ $feedback }}</native:text>
            @endif
        @endif

        @if ($ready && $selected !== null)
            @php($type = $types[$selected])

            <native:text class="text-lg font-bold text-theme-on-background">{{ $type['label'] }}</native:text>

            @foreach ($type['fields'] as $i => $field)
                {{-- One static block per position: native:model cannot take a dynamic path. --}}
                @if ($i === 0)
                    @if ($field['input'] === 'secret')
                        <outlined-text-input label="{{ $field['label'] }}" native:model="values.0" secure revealable ref="field-{{ $field['key'] }}" />
                    @else
                        <outlined-text-input label="{{ $field['label'] }}" native:model="values.0" keyboard="{{ $this->keyboardFor($field) }}" ref="field-{{ $field['key'] }}" />
                    @endif
                @elseif ($i === 1)
                    @if ($field['input'] === 'secret')
                        <outlined-text-input label="{{ $field['label'] }}" native:model="values.1" secure revealable ref="field-{{ $field['key'] }}" />
                    @else
                        <outlined-text-input label="{{ $field['label'] }}" native:model="values.1" keyboard="{{ $this->keyboardFor($field) }}" ref="field-{{ $field['key'] }}" />
                    @endif
                @elseif ($i === 2)
                    @if ($field['input'] === 'secret')
                        <outlined-text-input label="{{ $field['label'] }}" native:model="values.2" secure revealable ref="field-{{ $field['key'] }}" />
                    @else
                        <outlined-text-input label="{{ $field['label'] }}" native:model="values.2" keyboard="{{ $this->keyboardFor($field) }}" ref="field-{{ $field['key'] }}" />
                    @endif
                @endif

                @if (filled($field['help']))
                    <native:text class="text-sm text-theme-on-surface-variant">{{ $field['help'] }}</native:text>
                @endif

                @isset($errors[$field['key']])
                    <native:text class="text-sm text-theme-destructive">{{ $errors[$field['key']] }}</native:text>
                @endisset
            @endforeach

            <outlined-text-input label="Name (optional)" native:model="label" ref="field-label" />
            @isset($errors['label'])
                <native:text class="text-sm text-theme-destructive">{{ $errors['label'] }}</native:text>
            @endisset

            <native:column ref="channel-actions" class="w-full gap-3">
                @if ($feedback)
                    <native:text ref="channel-feedback" class="text-sm text-theme-destructive">{{ $feedback }}</native:text>
                @endif

                <native:button label="Send code" @tap="send" ref="send-code" class="w-full" />
                <native:button label="Choose another type" variant="secondary" @tap="chooseAnother" class="w-full" />
            </native:column>
        @endif
    </native:column>
</native:scroll-view>
