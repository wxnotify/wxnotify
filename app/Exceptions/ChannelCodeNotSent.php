<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The confirmation code could not be delivered (the destination refused it or the notification
 * service is unreachable).
 */
class ChannelCodeNotSent extends RuntimeException implements ShouldntReport
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'code_not_sent',
            'message' => 'We couldn\'t send the confirmation code. Please try again in a moment.',
        ], 502);
    }
}
