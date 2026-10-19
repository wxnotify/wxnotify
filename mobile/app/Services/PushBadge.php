<?php

namespace App\Services;

use App\Support\PushState;
use App\Support\TokenStore;
use Illuminate\Support\Facades\Log;
use Native\Mobile\Facades\PushNotifications;
use Throwable;

/**
 * Clears the app icon badge on this phone and resets the server's unread push count. A reset that
 * could not reach the server is remembered and retried on the next clear. Never throws: the badge
 * is a courtesy, and sign-out on an expired token is the screens' own calls to handle.
 */
class PushBadge
{
    public function __construct(private WxNotifyApi $api, private PushState $state, private TokenStore $tokens) {}

    public function clear(): void
    {
        try {
            PushNotifications::clearBadge();
        } catch (Throwable $e) {
            Log::info('Push badge local clear failed: '.$e::class);
        }

        $this->resetServerCount();
    }

    /**
     * Retries a reset that failed earlier, without touching the badge or the tray on this phone. Does
     * nothing when no reset is pending or the phone is signed out.
     */
    public function retryPendingReset(): void
    {
        if ($this->state->resetPending()) {
            $this->resetServerCount();
        }
    }

    private function resetServerCount(): void
    {
        if ($this->tokens->token() === null) {
            return;
        }

        try {
            $this->api->clearPushBadge();
        } catch (Throwable $e) {
            $this->state->markResetPending();
            Log::info('Push badge reset failed: '.$e::class);

            return;
        }

        $this->state->clearResetPending();
    }
}
