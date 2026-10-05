<?php

namespace App\Listeners;

use App\Services\PushRegistration;
use Native\Mobile\Events\PushNotification\TokenGenerated;

/**
 * A plain listener, not a screen handler, so a token the OS rotates is picked up even when no
 * push screen is mounted. Does nothing unless the subscriber turned push on and is signed in.
 */
class RegisterPushToken
{
    public function __construct(private PushRegistration $registration) {}

    public function handle(TokenGenerated $event): void
    {
        $this->registration->register($event->token);
    }
}
