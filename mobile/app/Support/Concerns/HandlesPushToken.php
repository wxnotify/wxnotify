<?php

namespace App\Support\Concerns;

use App\Services\PushRegistration;
use Native\Mobile\Attributes\On;
use Native\Mobile\Events\PushNotification\TokenGenerated;

/**
 * In the native runtime a plugin event reaches only the mounted screen's #[On] handlers, never a
 * plain Laravel listener, so every screen that can be showing when the OS hands over the FCM token
 * uses this. register() does nothing unless the subscriber turned push on and is signed in.
 */
trait HandlesPushToken
{
    #[On(TokenGenerated::class)]
    public function registerPushToken(string $token, ?string $id = null): void
    {
        app(PushRegistration::class)->register($token);
    }
}
