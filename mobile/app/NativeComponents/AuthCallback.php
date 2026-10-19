<?php

namespace App\NativeComponents;

use App\Exceptions\Api\ApiException;
use App\Exceptions\InvalidSignIn;
use App\Services\SignIn;
use App\Services\Welcome;
use Native\Mobile\Edge\NativeComponent;

/**
 * Where the browser sign-in lands (wxnotify://auth/callback/{code}/{state}).
 */
class AuthCallback extends NativeComponent
{
    public ?string $error = null;

    public function mount(): void
    {
        try {
            app(SignIn::class)->complete((string) $this->param('code'), (string) $this->param('state'));
        } catch (InvalidSignIn|ApiException) {
            $this->error = 'Sign-in could not be completed. Please try again.';

            return;
        }

        $this->replace(app(Welcome::class)->shouldShow() ? '/welcome' : '/');
    }

    public function tryAgain(): void
    {
        $this->replace('/sign-in');
    }
}
