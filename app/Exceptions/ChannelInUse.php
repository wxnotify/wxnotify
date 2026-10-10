<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The channel is some alert's only one; deleting it would leave that alert unable to deliver.
 */
class ChannelInUse extends RuntimeException implements ShouldntReport
{
    private const NAMED_ALERTS = 5;

    /**
     * @param  list<string>  $alertNames
     */
    public function __construct(public readonly string $channelLabel, public readonly array $alertNames)
    {
        parent::__construct('The channel is the only one for some alerts.');
    }

    public function render(): JsonResponse
    {
        $names = collect($this->alertNames);
        $listed = $names->take(self::NAMED_ALERTS)->implode(', ');
        $rest = $names->count() - self::NAMED_ALERTS;

        return response()->json([
            'error' => 'channel_in_use',
            'message' => "Can't delete {$this->channelLabel}. It's the only channel for: ".($rest > 0 ? "{$listed} (and {$rest} more)" : $listed).'. Add another channel to those alerts first.',
            'alerts' => $names->all(),
        ], 409);
    }
}
