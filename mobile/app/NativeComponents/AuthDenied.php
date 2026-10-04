<?php

namespace App\NativeComponents;

use App\Services\SignIn;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Where a cancelled browser sign-in lands (wxnotify://auth/denied/{state}).
 */
class AuthDenied extends NativeComponent
{
    public function mount(): void
    {
        app(SignIn::class)->denied();

        $this->replace('/sign-in');
    }

    public function render(): View
    {
        return view('native.auth-denied');
    }
}
