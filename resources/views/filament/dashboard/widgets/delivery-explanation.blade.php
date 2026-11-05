{{-- One delivery's explanation: $explanation is a DeliveryFailureExplanation::for() result, $channel the channel's label (or null), $when how long ago, $channelsUrl the channels page. Plain text only, escaped. --}}
<div class="space-y-3 text-sm">
    @if (filled($channel) || filled($when))
        <p class="text-gray-500 dark:text-gray-400">
            @if (filled($channel))
                Sent to <span class="font-medium text-gray-950 dark:text-white">{{ $channel }}</span>
            @endif
            @if (filled($when))
                {{ $when }}
            @endif
        </p>
    @endif

    <p class="font-medium text-gray-950 dark:text-white">{{ $explanation['reason'] }}</p>

    @if (filled($explanation['fix']))
        <div>
            <p class="font-medium text-gray-950 dark:text-white">What to do</p>
            <p class="text-gray-700 dark:text-gray-300">{{ $explanation['fix'] }}</p>
        </div>
    @endif

    @if ($explanation['channels'] && ! $explanation['ours'])
        <a href="{{ $channelsUrl }}" class="inline-block font-medium text-primary-600 hover:underline dark:text-primary-400">Go to your channels</a>
    @endif
</div>
