{{-- Un-prefixed on purpose: native:validate does not know <outlined-text-input>. --}}

<native:top-bar title="Channel" show-navigation-icon="true"></native:top-bar>

<native:scroll-view class="w-full h-full bg-theme-background">
    <native:column class="w-full p-4 gap-4">
        @if ($banner && ! $ready)
            <native:text class="text-sm text-theme-destructive">{{ $banner }}</native:text>
        @endif

        @if ($canRetry)
            <native:button label="Try again" variant="secondary" @tap="load" ref="retry-channel" class="w-full" />
        @endif

        @if ($ready)
            <native:text class="text-sm text-theme-on-surface-variant">{{ $channel['type_label'] }}</native:text>
            @if (filled($channel['destination'] ?? null))
                <native:text class="text-sm text-theme-on-surface-variant">{{ $channel['destination_label'] }}: {{ $channel['destination'] }}</native:text>
            @endif

            <outlined-text-input label="Name" native:model="draft.label" ref="field-label" />
            @isset($errors['label'])
                <native:text class="text-sm text-theme-destructive">{{ $errors['label'] }}</native:text>
            @endisset

            @unless ($this->isVerified())
                <native:text class="text-lg font-bold text-theme-on-background">Confirm this channel</native:text>
                <native:text class="text-base text-theme-on-surface-variant">We sent a 6-digit code to this channel. Enter it to start receiving alerts here.</native:text>
                <outlined-text-input label="Confirmation code" native:model="code" keyboard="number" ref="field-code" />
                @if ($codeError)
                    <native:text class="text-sm text-theme-destructive">{{ $codeError }}</native:text>
                @endif
                <native:button label="Verify" @tap="verify" ref="verify-channel" class="w-full" />
                <native:button label="Resend code" variant="secondary" @tap="resend" ref="resend-code" class="w-full" />
            @endunless

            {{-- The save/test/delete feedback and the confirmations sit right above the buttons that lead to them. --}}
            <native:column ref="channel-actions" class="w-full gap-3">
                @if ($banner)
                    <native:text ref="channel-feedback" class="text-sm text-theme-destructive">{{ $banner }}</native:text>
                @endif

                @if ($info)
                    <native:text ref="channel-info" class="text-sm text-theme-on-surface-variant">{{ $info }}</native:text>
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
                        <native:text class="text-base text-theme-on-background">Delete this channel? This can't be undone.</native:text>
                        <native:button label="Yes, delete" @tap="delete" class="w-full" />
                        <native:button label="Keep channel" variant="secondary" @tap="cancelDelete" class="w-full" />
                    </native:column>
                @endif

                <native:button label="Save" @tap="save" ref="save-channel" class="w-full" />

                @if ($this->isVerified())
                    <native:button label="Send test" variant="secondary" @tap="sendTest" ref="send-test" class="w-full" />
                @endif

                @unless ($confirmingDelete)
                    <native:button label="Delete channel" variant="secondary" @tap="askDelete" ref="delete-channel" class="w-full" />
                @endunless
            </native:column>
        @endif
    </native:column>
</native:scroll-view>
