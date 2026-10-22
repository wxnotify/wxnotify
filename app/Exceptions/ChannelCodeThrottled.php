<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A confirmation code was refused before anything was sent: the phone number or chat destination,
 * or the subscriber, has had too many codes this hour (DispatchChannelConfirmationCode's limits).
 */
class ChannelCodeThrottled extends RuntimeException implements ShouldntReport
{
    public const TITLE = 'Too many confirmation codes';

    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct(self::TITLE);
    }

    /**
     * What the subscriber is told, with the wait rounded up to whole minutes.
     */
    public function hint(): string
    {
        $minutes = max(1, (int) ceil($this->retryAfterSeconds / 60));

        return "We've sent several codes recently. Try again in {$minutes} ".($minutes === 1 ? 'minute' : 'minutes').'.';
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'too_many_codes',
            'message' => self::TITLE.'. '.$this->hint(),
        ], 429, ['Retry-After' => (string) $this->retryAfterSeconds]);
    }
}
