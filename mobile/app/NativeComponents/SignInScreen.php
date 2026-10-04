<?php

namespace App\NativeComponents;

use App\Services\SignIn;
use Native\Mobile\Edge\NativeComponent;

class SignInScreen extends NativeComponent
{
    public ?string $error = null;

    public function signIn(): void
    {
        $this->error = app(SignIn::class)->begin()
            ? null
            : 'Could not open the sign-in page. Check your connection and try again.';
    }
}
