<?php

namespace App\NativeComponents;

use App\Services\PushRegistration;
use App\Support\Concerns\HandlesPushToken;
use App\Support\Concerns\RequiresSignIn;
use Native\Mobile\Edge\NativeComponent;

/**
 * Shown once, right after the first sign-in: explains push before the OS asks, so the system
 * dialog is not the subscriber's first sight of it. "Not now" is remembered; Account can turn it
 * on later.
 */
class PushPrompt extends NativeComponent
{
    use HandlesPushToken;
    use RequiresSignIn;

    public function mount(): void
    {
        $this->redirectIfSignedOut();
    }

    public function enable(): void
    {
        app(PushRegistration::class)->enable();

        $this->replace('/');
    }

    public function notNow(): void
    {
        app(PushRegistration::class)->decline();

        $this->replace('/');
    }
}
