<?php

namespace App\NativeComponents;

use App\Services\SignIn;
use App\Support\TokenStore;
use Native\Mobile\Edge\NativeComponent;

class SignInScreen extends NativeComponent
{
    public ?string $error = null;

    public function mount(): void
    {
        $this->leaveIfSignedIn();
    }

    /**
     * A deep link that arrives while the app runs is pushed on top of this screen, so after the
     * callback replaces itself with the feed this screen is still underneath. Backing out to a
     * signed-in subscriber must not show it, and NativePHP has no way to reset the stack.
     */
    public function onResume(): void
    {
        $this->leaveIfSignedIn();
    }

    public function signIn(): void
    {
        $this->error = app(SignIn::class)->begin()
            ? null
            : 'Could not open the sign-in page. Check your connection and try again.';
    }

    private function leaveIfSignedIn(): void
    {
        if (app(TokenStore::class)->token() !== null) {
            $this->replace('/');
        }
    }
}
