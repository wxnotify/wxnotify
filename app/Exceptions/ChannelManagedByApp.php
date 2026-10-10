<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A phone's push channel is created and removed by the app's own push registration, never edited here.
 */
class ChannelManagedByApp extends RuntimeException implements ShouldntReport
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'channel_managed_by_app',
            'message' => 'A phone\'s push notifications are turned on or off in the app\'s Account screen.',
        ], 409);
    }
}
